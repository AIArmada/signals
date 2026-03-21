<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalAlertLog;
use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Models\SignalDailyMetric;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\TrackedProperty;
use Carbon\CarbonImmutable;

final class SignalsDashboardService
{
    /**
     * @return array{tracked_properties:int,identities:int,sessions:int,events:int,conversions:int,revenue_minor:int,active_alert_rules:int,unread_alerts:int}
     */
    public function summary(?TrackedProperty $trackedProperty = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array
    {
        [$startAt, $endAt] = $this->resolvePeriod($startAt, $endAt);

        $trackedProperties = TrackedProperty::query();
        $identities = SignalIdentity::query();
        $dailyMetrics = SignalDailyMetric::query()->whereBetween('date', [$startAt->toDateString(), $endAt->toDateString()]);
        $alertRules = SignalAlertRule::query()->where('is_active', true);
        $alertLogs = SignalAlertLog::query()
            ->where('is_read', false);

        if ($trackedProperty instanceof TrackedProperty) {
            $trackedProperties->whereKey($trackedProperty->getKey());
            $identities->where('tracked_property_id', $trackedProperty->id);
            $dailyMetrics->where('tracked_property_id', $trackedProperty->id);
            $alertRules->where('tracked_property_id', $trackedProperty->id);
            $alertLogs->where('tracked_property_id', $trackedProperty->id);
        }

        $identities->whereBetween('last_seen_at', [$startAt, $endAt]);

        return [
            'tracked_properties' => $trackedProperties->count(),
            'identities' => $identities->count(),
            'sessions' => (int) ((clone $dailyMetrics)->sum('sessions')),
            'events' => (int) ((clone $dailyMetrics)->sum('events')),
            'conversions' => (int) ((clone $dailyMetrics)->sum('conversions')),
            'revenue_minor' => (int) ((clone $dailyMetrics)->sum('revenue_minor')),
            'active_alert_rules' => $alertRules->count(),
            'unread_alerts' => $alertLogs->count(),
        ];
    }

    /**
     * @return array<int, array{date:string,events:int,conversions:int,revenue_minor:int}>
     */
    public function trend(?TrackedProperty $trackedProperty = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array
    {
        [$startAt, $endAt] = $this->resolvePeriod($startAt, $endAt);

        $query = SignalDailyMetric::query()
            ->whereBetween('date', [$startAt->toDateString(), $endAt->toDateString()])
            ->selectRaw('date as period')
            ->selectRaw('SUM(events) as events')
            ->selectRaw('SUM(conversions) as conversions')
            ->selectRaw('SUM(revenue_minor) as revenue_minor')
            ->groupBy('date')
            ->orderBy('date');

        if ($trackedProperty instanceof TrackedProperty) {
            $query->where('tracked_property_id', $trackedProperty->id);
        }

        $rows = $query->get();

        return $rows
            ->map(static function ($row): array {
                return [
                    'date' => (string) $row->getAttribute('period'),
                    'events' => (int) $row->getAttribute('events'),
                    'conversions' => (int) $row->getAttribute('conversions'),
                    'revenue_minor' => (int) $row->getAttribute('revenue_minor'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriod(?CarbonImmutable $startAt, ?CarbonImmutable $endAt): array
    {
        $resolvedEndAt = $endAt ?? CarbonImmutable::now()->endOfDay();
        $resolvedStartAt = $startAt ?? $resolvedEndAt->subDays(29)->startOfDay();

        return [$resolvedStartAt, $resolvedEndAt];
    }
}
