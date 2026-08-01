<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalDailyMetric;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Support\Carbon;

final class SignalMetricsAggregator
{
    public function aggregateForDate(Carbon $date, TrackedProperty $trackedProperty): SignalDailyMetric
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $events = SignalEvent::query()
            ->forOwner()
            ->where('tracked_property_id', $trackedProperty->id)
            ->whereBetween('occurred_at', [$start, $end]);

        $sessions = SignalSession::query()
            ->forOwner()
            ->where('tracked_property_id', $trackedProperty->id)
            ->whereBetween('started_at', [$start, $end]);

        $eventMetrics = (clone $events)
            ->selectRaw('COUNT(*) as events')
            ->selectRaw("SUM(CASE WHEN event_category = 'page_view' THEN 1 ELSE 0 END) as page_views")
            ->selectRaw("SUM(CASE WHEN event_category = 'conversion' THEN 1 ELSE 0 END) as conversions")
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) as revenue_minor')
            ->selectRaw('COUNT(DISTINCT signal_identity_id) as unique_identities')
            ->toBase()
            ->first();

        $sessionMetrics = (clone $sessions)
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced_sessions')
            ->toBase()
            ->first();

        return SignalDailyMetric::query()->forOwner()->updateOrCreate(
            [
                'tracked_property_id' => $trackedProperty->id,
                'date' => $date->toDateString(),
            ],
            [
                'owner_type' => $trackedProperty->owner_type,
                'owner_id' => $trackedProperty->owner_id,
                'unique_identities' => (int) ($eventMetrics->unique_identities ?? 0),
                'sessions' => (int) ($sessionMetrics->sessions ?? 0),
                'bounced_sessions' => (int) ($sessionMetrics->bounced_sessions ?? 0),
                'page_views' => (int) ($eventMetrics->page_views ?? 0),
                'events' => (int) ($eventMetrics->events ?? 0),
                'conversions' => (int) ($eventMetrics->conversions ?? 0),
                'revenue_minor' => (int) ($eventMetrics->revenue_minor ?? 0),
            ],
        );
    }

    public function backfill(Carbon $from, Carbon $to): int
    {
        $count = 0;

        TrackedProperty::query()
            ->forOwner()
            ->chunkById(100, function ($properties) use ($from, $to, &$count): void {
                $cursor = $from->copy();

                while ($cursor->lte($to)) {
                    $this->aggregateDateForProperties($cursor, $properties);
                    $count += $properties->count();
                    $cursor->addDay();
                }
            });

        return $count;
    }

    /**
     * Aggregate a date for a property chunk with one event and one session
     * aggregate query instead of repeating both scans for every property.
     * Daily metric models are still persisted through updateOrCreate so their
     * normal model lifecycle remains intact.
     */
    private function aggregateDateForProperties(Carbon $date, mixed $properties): void
    {
        $propertyIds = $properties->pluck('id')->all();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $eventMetrics = SignalEvent::query()
            ->forOwner()
            ->whereIn('tracked_property_id', $propertyIds)
            ->whereBetween('occurred_at', [$start, $end])
            ->select('tracked_property_id')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw("SUM(CASE WHEN event_category = 'page_view' THEN 1 ELSE 0 END) as page_views")
            ->selectRaw("SUM(CASE WHEN event_category = 'conversion' THEN 1 ELSE 0 END) as conversions")
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) as revenue_minor')
            ->selectRaw('COUNT(DISTINCT signal_identity_id) as unique_identities')
            ->groupBy('tracked_property_id')
            ->get()
            ->keyBy('tracked_property_id');

        $sessionMetrics = SignalSession::query()
            ->forOwner()
            ->whereIn('tracked_property_id', $propertyIds)
            ->whereBetween('started_at', [$start, $end])
            ->select('tracked_property_id')
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced_sessions')
            ->groupBy('tracked_property_id')
            ->get()
            ->keyBy('tracked_property_id');

        foreach ($properties as $trackedProperty) {
            $eventMetric = $eventMetrics->get($trackedProperty->id);
            $sessionMetric = $sessionMetrics->get($trackedProperty->id);

            SignalDailyMetric::query()->forOwner()->updateOrCreate(
                [
                    'tracked_property_id' => $trackedProperty->id,
                    'date' => $date->toDateString(),
                ],
                [
                    'owner_type' => $trackedProperty->owner_type,
                    'owner_id' => $trackedProperty->owner_id,
                    'unique_identities' => (int) ($eventMetric->unique_identities ?? 0),
                    'sessions' => (int) ($sessionMetric->sessions ?? 0),
                    'bounced_sessions' => (int) ($sessionMetric->bounced_sessions ?? 0),
                    'page_views' => (int) ($eventMetric->page_views ?? 0),
                    'events' => (int) ($eventMetric->events ?? 0),
                    'conversions' => (int) ($eventMetric->conversions ?? 0),
                    'revenue_minor' => (int) ($eventMetric->revenue_minor ?? 0),
                ],
            );
        }
    }
}
