<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Queries;

use AIArmada\Signals\Features\Ingestion\Api\EventMetric;
use AIArmada\Signals\Features\Ingestion\Api\EventMetricQuery;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Support\SignalCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

final readonly class CalculateEventMetric implements EventMetricQuery
{
    public function value(EventMetric $rule): float
    {
        if (! $this->hasPropertyFilters($rule)) {
            return $this->calculateDatabaseMetricValue($rule);
        }

        $events = $this->filteredEvents($rule);

        return match ($rule->metricKey) {
            'events', 'event_count' => (float) $events->count(),
            'page_views' => (float) $events->where('event_category', 'page_view')->count(),
            'conversions' => (float) $events->where('event_category', 'conversion')->count(),
            'revenue_minor' => $this->sumStreamedRevenue($events, $rule->revenueCurrency),
            'conversion_rate' => $this->calculateConversionRate($events),
            default => $this->calculatePropertyMetric($rule->metricKey, $events),
        };
    }

    private function calculateDatabaseMetricValue(EventMetric $rule): float
    {
        $query = $this->baseQuery($rule);

        if ($rule->metricKey === 'conversion_rate') {
            $totals = $query
                ->selectRaw("SUM(CASE WHEN event_category = 'page_view' THEN 1 ELSE 0 END) as page_views")
                ->selectRaw("SUM(CASE WHEN event_category = 'conversion' THEN 1 ELSE 0 END) as conversions")
                ->toBase()
                ->first();
            $pageViews = (float) ($totals->page_views ?? 0);

            return $pageViews === 0.0
                ? 0.0
                : round(((float) ($totals->conversions ?? 0) / $pageViews) * 100, 4);
        }

        return match ($rule->metricKey) {
            'events', 'event_count' => (float) $query->count(),
            'page_views' => (float) (clone $query)->where('event_category', 'page_view')->count(),
            'conversions' => (float) (clone $query)->where('event_category', 'conversion')->count(),
            'revenue_minor' => $this->sumDatabaseRevenue($query, $rule->revenueCurrency),
            default => $this->calculatePropertyMetric($rule->metricKey, $this->filteredEvents($rule)),
        };
    }

    /**
     * @param  Builder<SignalEvent>  $query
     */
    private function sumDatabaseRevenue(Builder $query, ?string $currency): float
    {
        if ($currency === null) {
            return 0.0;
        }

        return (float) $query
            ->whereRaw('UPPER(currency) = ?', [$currency])
            ->sum('revenue_minor');
    }

    /**
     * @param  LazyCollection<int, SignalEvent>  $events
     */
    private function sumStreamedRevenue(LazyCollection $events, ?string $currency): float
    {
        if ($currency === null) {
            return 0.0;
        }

        return (float) $events
            ->filter(fn (SignalEvent $event): bool => SignalCurrency::matches($event->currency, $currency))
            ->sum('revenue_minor');
    }

    private function hasPropertyFilters(EventMetric $rule): bool
    {
        $filters = $this->eventFilters($rule);
        $conditions = $filters['properties'] ?? ($filters['property_conditions'] ?? []);

        return is_array($conditions) && $conditions !== [];
    }

    /**
     * @param  LazyCollection<int, SignalEvent>  $events
     */
    private function calculateConversionRate(LazyCollection $events): float
    {
        $pageViews = 0;
        $conversions = 0;

        foreach ($events as $event) {
            $pageViews += $event->event_category === 'page_view' ? 1 : 0;
            $conversions += $event->event_category === 'conversion' ? 1 : 0;
        }

        return $pageViews === 0 ? 0.0 : round(($conversions / $pageViews) * 100, 4);
    }

    /**
     * @param  LazyCollection<int, SignalEvent>  $events
     */
    private function calculatePropertyMetric(string $metricKey, LazyCollection $events): float
    {
        if (! str_contains($metricKey, ':')) {
            return 0.0;
        }

        [$aggregate, $propertyKey] = explode(':', $metricKey, 2);
        $count = 0;
        $sum = 0.0;
        $min = null;
        $max = null;

        foreach ($events as $event) {
            $value = $event->properties[$propertyKey] ?? null;

            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            $count++;
            $sum += $value;
            $min = $min === null ? $value : min($min, $value);
            $max = $max === null ? $value : max($max, $value);
        }

        return match ($aggregate) {
            'property_sum' => $sum,
            'property_avg' => $count > 0 ? $sum / $count : 0.0,
            'property_min' => (float) ($min ?? 0),
            'property_max' => (float) ($max ?? 0),
            default => 0.0,
        };
    }

    /**
     * Stream matching events instead of hydrating the whole window, so
     * high-volume timeframes cannot exhaust memory while filtering.
     *
     * @return LazyCollection<int, SignalEvent>
     */
    private function filteredEvents(EventMetric $rule): LazyCollection
    {
        $filters = $this->eventFilters($rule);

        return $this->baseQuery($rule)
            ->cursor()
            ->filter(fn (SignalEvent $event): bool => $this->matchesPropertyFilters($event, $filters))
            ->values();
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function baseQuery(EventMetric $rule): Builder
    {
        $from = CarbonImmutable::now()->subMinutes($rule->timeframeMinutes);

        return SignalEvent::query()
            ->forOwner()
            ->when(
                filled($rule->trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $rule->trackedPropertyId)
            )
            ->when(
                $this->eventNames($rule) !== [],
                fn (Builder $query): Builder => $query->whereIn('event_name', $this->eventNames($rule)),
            )
            ->when(
                $this->eventCategories($rule) !== [],
                fn (Builder $query): Builder => $query->whereIn('event_category', $this->eventCategories($rule)),
            )
            ->whereBetween('occurred_at', [$from, CarbonImmutable::now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventFilters(EventMetric $rule): array
    {
        return $rule->eventFilters;
    }

    /**
     * @return list<string>
     */
    private function eventNames(EventMetric $rule): array
    {
        $names = $this->eventFilters($rule)['event_names'] ?? [];

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }

    /**
     * @return list<string>
     */
    private function eventCategories(EventMetric $rule): array
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
        if ($operator === 'in') {
            return is_array($expected) && in_array($actual, $expected, true);
        }

        if ($operator === 'contains') {
            return is_string($actual) && is_string($expected) && str_contains($actual, $expected);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return $this->compare((float) $actual, $operator, (float) $expected);
        }

        return match ($operator) {
            '!=', '<>', 'not_eq' => $actual !== $expected,
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
            '!=', '<>', 'not_eq' => $actual !== $expected,
            '=', '==', 'eq' => $actual === $expected,
            default => $actual === $expected,
        };
    }
}
