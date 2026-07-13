<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Jobs\DispatchSignalAlertDelivery;
use AIArmada\Signals\Models\SignalAlertDelivery;
use AIArmada\Signals\Models\SignalAlertLog;
use AIArmada\Signals\Models\SignalAlertRule;
use Illuminate\Support\Facades\DB;

final class SignalAlertDispatcher
{
    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(SignalAlertRule $rule, float $metricValue, array $context = []): SignalAlertLog
    {
        $message = $this->buildMessage($rule, $metricValue);
        $deliveries = [];

        $log = DB::transaction(function () use ($rule, $metricValue, $context, $message, &$deliveries): SignalAlertLog {
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

            foreach ($this->channelsFor($rule) as $channel) {
                if ($channel === 'database') {
                    continue;
                }

                foreach ($this->destinationsFor($channel, $rule) as $entry) {
                    $delivery = SignalAlertDelivery::query()->firstOrCreate(
                        [
                            'signal_alert_log_id' => $log->id,
                            'channel' => $channel,
                            'destination_key' => $entry['key'],
                        ],
                        [
                            'destination' => $entry['destination'],
                            'status' => 'pending',
                            'max_attempts' => max(1, (int) config('signals.features.alerts.delivery.max_attempts', 5)),
                            'available_at' => now(),
                            'owner_type' => $log->owner_type,
                            'owner_id' => $log->owner_id,
                        ],
                    );
                    $deliveries[] = $delivery;
                }
            }

            $this->refreshLogSummary($log);
            $rule->markTriggered();

            return $log;
        });

        foreach ($deliveries as $delivery) {
            DispatchSignalAlertDelivery::dispatch(
                deliveryId: (string) $delivery->id,
                ownerType: $delivery->owner_type,
                ownerId: $delivery->owner_id,
                ownerIsGlobal: $delivery->owner_type === null && $delivery->owner_id === null,
            )->onQueue((string) config('signals.features.alerts.delivery.queue', 'default'));
        }

        return $log->refresh();
    }

    public function refreshLogSummary(SignalAlertLog $log): void
    {
        $deliveries = SignalAlertDelivery::query()
            ->where('signal_alert_log_id', $log->id)
            ->get();
        $results = ['database' => ['status' => 'created']];
        $notified = ['database'];

        foreach ($deliveries->groupBy('channel') as $channel => $channelDeliveries) {
            $counts = $channelDeliveries->countBy('status')->all();
            $status = match (true) {
                ($counts['dead'] ?? 0) > 0 => 'failed',
                ($counts['failed'] ?? 0) > 0 => 'retrying',
                ($counts['processing'] ?? 0) > 0 => 'processing',
                ($counts['pending'] ?? 0) > 0 => 'queued',
                ($counts['sent'] ?? 0) === $channelDeliveries->count() => 'sent',
                default => 'queued',
            };

            $results[(string) $channel] = [
                'status' => $status,
                'total' => $channelDeliveries->count(),
                'sent' => (int) ($counts['sent'] ?? 0),
                'failed' => (int) (($counts['failed'] ?? 0) + ($counts['dead'] ?? 0)),
            ];

            if (($counts['sent'] ?? 0) > 0) {
                $notified[] = (string) $channel;
            }
        }

        $log->forceFill([
            'channels_notified' => array_values(array_unique($notified)),
            'delivery_results' => $results,
        ])->save();
    }

    /** @return list<string> */
    private function channelsFor(SignalAlertRule $rule): array
    {
        $channels = is_array($rule->channels) && $rule->channels !== []
            ? $rule->channels
            : config('signals.features.alerts.default_channels', ['database']);
        $channels = is_array($channels) ? array_values(array_unique(array_filter($channels, 'is_string'))) : ['database'];

        return in_array('database', $channels, true) ? $channels : ['database', ...$channels];
    }

    /** @return list<array{key:string,destination:array<string,mixed>}> */
    private function destinationsFor(string $channel, SignalAlertRule $rule): array
    {
        $configured = config("signals.features.alerts.destinations.{$channel}", []);
        $configured = is_array($configured) ? $configured : [];
        $keys = is_array($rule->destination_keys) ? $rule->destination_keys : [];
        $destinations = [];

        foreach ($keys as $key) {
            if (is_string($key) && is_array($configured[$key] ?? null)) {
                $destinations[] = ['key' => $key, 'destination' => $configured[$key]];
            }
        }

        if ((bool) config('signals.features.alerts.allow_inline_destinations', false)) {
            $inline = is_array($rule->inline_destinations) ? ($rule->inline_destinations[$channel] ?? []) : [];
            $inline = is_array($inline) ? (array_is_list($inline) ? $inline : [$inline]) : [];

            foreach ($inline as $destination) {
                if (! is_array($destination)) {
                    continue;
                }

                $canonical = $destination;
                ksort($canonical);
                $destinations[] = [
                    'key' => 'inline-' . substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), 0, 32),
                    'destination' => $destination,
                ];
            }
        }

        return $destinations;
    }

    private function buildMessage(SignalAlertRule $rule, float $metricValue): string
    {
        return sprintf(
            '%s triggered: %s %s %s over the last %d minute(s). Current value: %s.',
            $rule->name,
            $rule->metric_key,
            $rule->operator,
            number_format($rule->threshold, 4, '.', ''),
            $rule->timeframe_minutes,
            number_format($metricValue, 4, '.', ''),
        );
    }
}
