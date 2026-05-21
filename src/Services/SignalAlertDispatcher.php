<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalAlertLog;
use AIArmada\Signals\Models\SignalAlertRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SignalAlertDispatcher
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(SignalAlertRule $rule, float $metricValue, array $context = []): SignalAlertLog
    {
        $channels = $this->channelsFor($rule);
        $message = $this->buildMessage($rule, $metricValue);

        $log = SignalAlertLog::query()->create([
            'signal_alert_rule_id' => $rule->id,
            'tracked_property_id' => $rule->tracked_property_id,
            'metric_key' => $rule->metric_key,
            'operator' => $rule->operator,
            'metric_value' => $metricValue,
            'threshold_value' => $rule->threshold,
            'severity' => $rule->severity,
            'title' => $rule->name,
            'message' => $message,
            'context' => $context,
            'channels_notified' => ['database'],
            'delivery_results' => ['database' => ['status' => 'created']],
        ]);

        $deliveryResults = $log->delivery_results ?? [];

        foreach ($channels as $channel) {
            if ($channel === 'database') {
                continue;
            }

            $deliveryResults[$channel] = $this->dispatchChannel($channel, $rule, $log, $message);
        }

        $notified = array_values(array_filter(
            array_keys($deliveryResults),
            static fn (string $channel): bool => ($deliveryResults[$channel]['status'] ?? null) !== 'skipped',
        ));

        $log->forceFill([
            'channels_notified' => $notified,
            'delivery_results' => $deliveryResults,
        ])->save();

        $rule->markTriggered();

        return $log;
    }

    /**
     * @return list<string>
     */
    private function channelsFor(SignalAlertRule $rule): array
    {
        $channels = $rule->channels;

        if (! is_array($channels) || $channels === []) {
            $channels = config('signals.features.alerts.default_channels', ['database']);
        }

        $channels = is_array($channels) ? $channels : ['database'];
        $channels = array_values(array_unique(array_filter($channels, 'is_string')));

        return in_array('database', $channels, true) ? $channels : array_merge(['database'], $channels);
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchChannel(string $channel, SignalAlertRule $rule, SignalAlertLog $log, string $message): array
    {
        $destinations = $this->destinationsFor($channel, $rule);

        if ($destinations === []) {
            return ['status' => 'skipped', 'reason' => 'no_destination'];
        }

        try {
            foreach ($destinations as $destination) {
                match ($channel) {
                    'email' => $this->sendEmail($destination, $rule, $message),
                    'webhook' => $this->sendWebhook($destination, $rule, $log, $message),
                    'slack' => $this->sendSlack($destination, $rule, $log, $message),
                    default => null,
                };
            }
        } catch (Throwable $throwable) {
            return ['status' => 'failed', 'message' => $throwable->getMessage()];
        }

        return ['status' => 'sent', 'count' => count($destinations)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function destinationsFor(string $channel, SignalAlertRule $rule): array
    {
        $configured = config("signals.features.alerts.destinations.{$channel}", []);
        $configured = is_array($configured) ? $configured : [];
        $destinationKeys = is_array($rule->destination_keys) ? $rule->destination_keys : [];
        $destinations = [];

        foreach ($destinationKeys as $key) {
            if (is_string($key) && is_array($configured[$key] ?? null)) {
                $destinations[] = $configured[$key];
            }
        }

        if ((bool) config('signals.features.alerts.allow_inline_destinations', false)) {
            $inline = is_array($rule->inline_destinations) ? ($rule->inline_destinations[$channel] ?? []) : [];

            if (is_array($inline)) {
                $destinations = array_merge($destinations, array_is_list($inline) ? $inline : [$inline]);
            }
        }

        return array_values(array_filter($destinations, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function sendEmail(array $destination, SignalAlertRule $rule, string $message): void
    {
        $to = $destination['to'] ?? null;

        if (! is_string($to) || $to === '') {
            return;
        }

        Mail::raw($message, static function ($mail) use ($to, $rule): void {
            $mail->to($to)->subject('[Signals] ' . $rule->name);
        });
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function sendWebhook(array $destination, SignalAlertRule $rule, SignalAlertLog $log, string $message): void
    {
        $url = $destination['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        Http::post($url, $this->webhookPayload($rule, $log, $message));
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function sendSlack(array $destination, SignalAlertRule $rule, SignalAlertLog $log, string $message): void
    {
        $url = $destination['webhook_url'] ?? ($destination['url'] ?? null);

        if (! is_string($url) || $url === '') {
            return;
        }

        Http::post($url, [
            'text' => $message,
            'attachments' => [[
                'title' => $rule->name,
                'color' => $rule->severity === 'critical' ? 'danger' : 'warning',
                'fields' => [
                    ['title' => 'Metric', 'value' => $rule->metric_key, 'short' => true],
                    ['title' => 'Log', 'value' => $log->id, 'short' => true],
                ],
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookPayload(SignalAlertRule $rule, SignalAlertLog $log, string $message): array
    {
        return [
            'alert_rule_id' => $rule->id,
            'alert_log_id' => $log->id,
            'name' => $rule->name,
            'severity' => $rule->severity,
            'message' => $message,
            'context' => $log->context,
        ];
    }

    private function buildMessage(SignalAlertRule $rule, float $metricValue): string
    {
        return sprintf(
            '%s triggered: %s %s %s over the last %d minute(s).',
            $rule->name,
            $rule->metric_key,
            $rule->operator,
            number_format($rule->threshold, 4, '.', ''),
            $rule->timeframe_minutes,
        ) . sprintf(' Current value: %s.', number_format($metricValue, 4, '.', ''));
    }
}
