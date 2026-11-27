<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\Signals\Features\Alerts\EntryPoints\Jobs\DispatchSignalAlertDelivery;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertDelivery;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;
use AIArmada\Signals\Features\Alerts\Support\AlertDestinationPolicy;
use Carbon\CarbonImmutable;

final readonly class SignalAlertDispatcher
{
    public function __construct(private AlertDestinationPolicy $destinations) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(SignalAlertRule $rule, float $metricValue, array $context = []): ?SignalAlertLog
    {
        $deliveries = [];

        $log = $rule->getConnection()->transaction(function () use ($rule, $metricValue, $context, &$deliveries): ?SignalAlertLog {
            $current = SignalAlertRule::query()->forOwner()->whereKey($rule->getKey())->lockForUpdate()->first();

            if ($current === null || ! $current->is_active || $current->isInCooldown()) {
                return null;
            }

            // Never dispatch an evaluation calculated against a concurrently edited definition.
            foreach (['tracked_property_id', 'metric_key', 'operator', 'threshold', 'timeframe_minutes', 'event_filters'] as $field) {
                if ($current->getAttribute($field) !== $rule->getAttribute($field)) {
                    return null;
                }
            }

            $rule = $current;
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
                            'available_at' => CarbonImmutable::now(),
                            'owner_type' => $log->owner_type,
                            'owner_id' => $log->owner_id,
                        ],
                    );
                    $deliveries[] = $delivery;
                }
            }

            $this->refreshLogSummary($log);
            $rule->update(['last_triggered_at' => CarbonImmutable::now()]);

            return $log;
        });

        if ($log === null) {
            return null;
        }

        foreach ($deliveries as $delivery) {
            DispatchSignalAlertDelivery::dispatch(
                deliveryId: (string) $delivery->id,
                ownerType: $delivery->owner_type,
                ownerId: $delivery->owner_id,
                ownerIsGlobal: $delivery->owner_type === null && $delivery->owner_id === null,
            )->onQueue((string) config('signals.features.alerts.delivery.queue', 'default'))->afterCommit();
        }

        return $log->refresh();
    }

    public function refreshLogSummary(SignalAlertLog $log): void
    {
        $log->getConnection()->transaction(function () use ($log): void {
            $lockedLog = SignalAlertLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            $this->writeLogSummary($lockedLog);
        });
    }

    private function writeLogSummary(SignalAlertLog $log): void
    {
        $deliveries = SignalAlertDelivery::query()
            ->where('signal_alert_log_id', $log->id)
            ->select(['channel', 'status'])
            ->selectRaw('COUNT(*) AS delivery_count')
            ->groupBy('channel', 'status')
            ->toBase()
            ->get();
        $results = ['database' => ['status' => 'created']];
        $notified = ['database'];

        foreach ($deliveries->groupBy('channel') as $channel => $channelDeliveries) {
            $counts = $channelDeliveries->mapWithKeys(static fn ($row): array => [(string) $row->status => (int) $row->delivery_count])->all();
            $total = array_sum($counts);
            $status = match (true) {
                ($counts['dead'] ?? 0) > 0 => 'failed',
                ($counts['failed'] ?? 0) > 0 => 'retrying',
                ($counts['processing'] ?? 0) > 0 => 'processing',
                ($counts['pending'] ?? 0) > 0 => 'queued',
                ($counts['sent'] ?? 0) === $total => 'sent',
                default => 'queued',
            };

            $results[(string) $channel] = [
                'status' => $status,
                'total' => $total,
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

        if ($this->destinations->configuredDestinationsAllowedForRule($rule)) {
            foreach ($keys as $key) {
                if (is_string($key) && is_array($configured[$key] ?? null)) {
                    $destinations[] = ['key' => $key, 'destination' => $configured[$key]];
                }
            }
        }

        if ((bool) config('signals.features.alerts.allow_inline_destinations', false)) {
            $inline = is_array($rule->inline_destinations) ? $rule->inline_destinations : [];

            foreach ($inline as $entry) {
                if (! is_array($entry) || ($entry['channel'] ?? null) !== $channel) {
                    continue;
                }

                $destination = $entry['destination'] ?? null;

                if (! is_array($destination) || $destination === []) {
                    continue;
                }

                $canonical = $destination;
                ksort($canonical);
                $destinations[] = [
                    'key' => 'inline-' . mb_substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), 0, 32),
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
