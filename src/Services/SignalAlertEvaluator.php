<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Models\SignalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class SignalAlertEvaluator
{
    /**
     * @return array{matched: bool, metric_value: float, context: array<string, mixed>}
     */
    public function evaluate(SignalAlertRule $rule): array
    {
        $metricValue = $this->calculateMetricValue($rule);

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

    private function calculateMetricValue(SignalAlertRule $rule): float
    {
        $query = $this->baseQuery($rule);

        return match ($rule->metric_key) {
            'events' => (float) (clone $query)->count(),
            'page_views' => (float) (clone $query)->where('event_category', 'page_view')->count(),
            'conversions' => (float) (clone $query)->where('event_category', 'conversion')->count(),
            'revenue_minor' => (float) (clone $query)->sum('revenue_minor'),
            'conversion_rate' => $this->calculateConversionRate($query),
            default => 0.0,
        };
    }

    /**
     * @param  Builder<SignalEvent>  $query
     */
    private function calculateConversionRate(Builder $query): float
    {
        $pageViews = (float) (clone $query)->where('event_category', 'page_view')->count();

        if ($pageViews === 0.0) {
            return 0.0;
        }

        $conversions = (float) (clone $query)->where('event_category', 'conversion')->count();

        return round(($conversions / $pageViews) * 100, 4);
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function baseQuery(SignalAlertRule $rule): Builder
    {
        $from = CarbonImmutable::now()->subMinutes($rule->timeframe_minutes);

        return SignalEvent::query()
            ->when(
                filled($rule->tracked_property_id),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $rule->tracked_property_id)
            )
            ->where('occurred_at', '>=', $from);
    }

    private function compare(float $actual, string $operator, float $expected): bool
    {
        return match ($operator) {
            '>', 'gt' => $actual > $expected,
            '>=' , 'gte' => $actual >= $expected,
            '<', 'lt' => $actual < $expected,
            '<=', 'lte' => $actual <= $expected,
            '!=', '<>' => $actual !== $expected,
            '=', '==', 'eq' => $actual === $expected,
            default => $actual === $expected,
        };
    }
}
