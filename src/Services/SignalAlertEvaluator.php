<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Models\SignalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        $events = $this->filteredEvents($rule);

        return match ($rule->metric_key) {
            'events', 'event_count' => (float) $events->count(),
            'page_views' => (float) $events->where('event_category', 'page_view')->count(),
            'conversions' => (float) $events->where('event_category', 'conversion')->count(),
            'revenue_minor' => (float) $events->sum('revenue_minor'),
            'conversion_rate' => $this->calculateConversionRate($events),
            default => $this->calculatePropertyMetric($rule->metric_key, $events),
        };
    }

    /**
     * @param  Collection<int, SignalEvent>  $events
     */
    private function calculateConversionRate(Collection $events): float
    {
        $pageViews = (float) $events->where('event_category', 'page_view')->count();

        if ($pageViews === 0.0) {
            return 0.0;
        }

        $conversions = (float) $events->where('event_category', 'conversion')->count();

        return round(($conversions / $pageViews) * 100, 4);
    }

    /**
     * @param  Collection<int, SignalEvent>  $events
     */
    private function calculatePropertyMetric(string $metricKey, Collection $events): float
    {
        if (! str_contains($metricKey, ':')) {
            return 0.0;
        }

        [$aggregate, $propertyKey] = explode(':', $metricKey, 2);
        $values = $events
            ->map(fn (SignalEvent $event): mixed => $event->properties[$propertyKey] ?? null)
            ->filter(static fn (mixed $value): bool => is_int($value) || is_float($value));

        return match ($aggregate) {
            'property_sum' => (float) $values->sum(),
            'property_avg' => $values->count() > 0 ? (float) $values->avg() : 0.0,
            'property_min' => $values->count() > 0 ? (float) $values->min() : 0.0,
            'property_max' => $values->count() > 0 ? (float) $values->max() : 0.0,
            default => 0.0,
        };
    }

    /**
     * @return Collection<int, SignalEvent>
     */
    private function filteredEvents(SignalAlertRule $rule): Collection
    {
        $filters = $this->eventFilters($rule);

        return $this->baseQuery($rule)
            ->get()
            ->filter(fn (SignalEvent $event): bool => $this->matchesPropertyFilters($event, $filters))
            ->values();
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
            ->when(
                $this->eventNames($rule) !== [],
                fn (Builder $query): Builder => $query->whereIn('event_name', $this->eventNames($rule)),
            )
            ->when(
                $this->eventCategories($rule) !== [],
                fn (Builder $query): Builder => $query->whereIn('event_category', $this->eventCategories($rule)),
            )
            ->where('occurred_at', '>=', $from);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventFilters(SignalAlertRule $rule): array
    {
        return is_array($rule->event_filters) ? $rule->event_filters : [];
    }

    /**
     * @return list<string>
     */
    private function eventNames(SignalAlertRule $rule): array
    {
        $names = $this->eventFilters($rule)['event_names'] ?? [];

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }

    /**
     * @return list<string>
     */
    private function eventCategories(SignalAlertRule $rule): array
    {
        $categories = $this->eventFilters($rule)['event_categories'] ?? [];

        return is_array($categories) ? array_values(array_filter($categories, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesPropertyFilters(SignalEvent $event, array $filters): bool
    {
        $conditions = $filters['properties'] ?? ($filters['property_conditions'] ?? []);

        if (! is_array($conditions) || $conditions === []) {
            return true;
        }

        foreach ($conditions as $key => $condition) {
            if (is_array($condition)) {
                $propertyKey = is_string($condition['key'] ?? null) ? $condition['key'] : null;
                $operator = is_string($condition['operator'] ?? null) ? $condition['operator'] : 'eq';
                $expected = $condition['value'] ?? null;
            } else {
                $propertyKey = is_string($key) ? $key : null;
                $operator = 'eq';
                $expected = $condition;
            }

            if ($propertyKey === null || ! $this->compareProperty($event->properties[$propertyKey] ?? null, $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function compareProperty(mixed $actual, string $operator, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return $this->compare((float) $actual, $operator, (float) $expected);
        }

        return match ($operator) {
            '!=', '<>', 'not_eq' => $actual !== $expected,
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'in' => is_array($expected) && in_array($actual, $expected, true),
            default => $actual === $expected,
        };
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
