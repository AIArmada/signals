<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Queries;

use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;
use AIArmada\Signals\Features\Ingestion\Api\EventMetric;
use AIArmada\Signals\Features\Ingestion\Api\EventMetricQuery;
use Carbon\CarbonImmutable;

final readonly class SignalAlertEvaluator implements AlertEvaluator
{
    public function __construct(private EventMetricQuery $metrics) {}

    /**
     * @return array{matched: bool, metric_value: float, context: array<string, mixed>}
     */
    public function evaluate(SignalAlertRule $rule, ?string $revenueCurrency = null): array
    {
        $metricValue = $this->metrics->value(new EventMetric(
            metricKey: $rule->metric_key,
            trackedPropertyId: $rule->tracked_property_id,
            timeframeMinutes: $rule->timeframe_minutes,
            eventFilters: $rule->event_filters ?? [],
            revenueCurrency: $rule->metric_key === 'revenue_minor'
                ? ($revenueCurrency ?? $rule->revenueCurrency())
                : null,
        ));

        return [
            'matched' => $this->compare($metricValue, $rule->operator, $rule->threshold),
            'metric_value' => $metricValue,
            'context' => [
                'metric_key' => $rule->metric_key,
                'timeframe_minutes' => $rule->timeframe_minutes,
                'tracked_property_id' => $rule->tracked_property_id,
                'evaluated_at' => CarbonImmutable::now()->toIso8601String(),
            ],
        ];
    }

    private function compare(float $actual, string $operator, float $expected): bool
    {
        return match ($operator) {
            '>', 'gt' => $actual > $expected,
            '>=' , 'gte' => $actual >= $expected,
            '<', 'lt' => $actual < $expected,
            '<=', 'lte' => $actual <= $expected,
            '!=', '<>', 'not_eq' => $actual !== $expected,
            '=', '==', 'eq' => $actual === $expected,
            default => $actual === $expected,
        };
    }
}
