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

        return SignalDailyMetric::query()->forOwner()->updateOrCreate(
            [
                'tracked_property_id' => $trackedProperty->id,
                'date' => $date->toDateString(),
            ],
            [
                'owner_type' => $trackedProperty->owner_type,
                'owner_id' => $trackedProperty->owner_id,
                'unique_identities' => (clone $events)
                    ->whereNotNull('signal_identity_id')
                    ->distinct('signal_identity_id')
                    ->count('signal_identity_id'),
                'sessions' => $sessions->count(),
                'bounced_sessions' => (clone $sessions)->where('is_bounce', true)->count(),
                'page_views' => (clone $events)->where('event_category', 'page_view')->count(),
                'events' => $events->count(),
                'conversions' => (clone $events)->where('event_category', 'conversion')->count(),
                'revenue_minor' => (int) ((clone $events)->sum('revenue_minor')),
            ],
        );
    }

    public function backfill(Carbon $from, Carbon $to): int
    {
        $count = 0;

        TrackedProperty::query()
            ->forOwner()
            ->chunkById(100, function ($properties) use ($from, $to, &$count): void {
                foreach ($properties as $trackedProperty) {
                    $cursor = $from->copy();

                    while ($cursor->lte($to)) {
                        $this->aggregateForDate($cursor, $trackedProperty);
                        $cursor->addDay();
                        $count++;
                    }
                }
            });

        return $count;
    }
}
