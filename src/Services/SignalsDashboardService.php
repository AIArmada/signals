<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\SignalAlertLog;
use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Models\SignalDailyMetric;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Reports\ReportRegistry;
use AIArmada\Signals\Support\SignalCurrency;
use AIArmada\Signals\Support\SignalReportRevenue;
use Carbon\CarbonImmutable;

final class SignalsDashboardService
{
    public function __construct(
        private readonly ReportRegistry $reportRegistry,
    ) {}

    /**
     * @return array{tracked_properties:int,identities:int,sessions:int,events:int,conversions:int,active_alert_rules:int,unread_alerts:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?TrackedProperty $trackedProperty = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array
    {
        return $this->withResolvedOwnerOrExplicitGlobal(function () use ($trackedProperty, $startAt, $endAt): array {
            [$startAt, $endAt] = $this->resolvePeriod($startAt, $endAt);

            $trackedProperties = TrackedProperty::query();
            $identities = SignalIdentity::query();
            $dailyMetrics = SignalDailyMetric::query()->whereBetween('date', [$startAt->toDateString(), $endAt->toDateString()]);
            $alertRules = SignalAlertRule::query()->where('is_active', true);
            $alertLogs = SignalAlertLog::query()
                ->whereNull('read_at');

            if ($trackedProperty instanceof TrackedProperty) {
                $trackedProperties->whereKey($trackedProperty->getKey());
                $identities->where('tracked_property_id', $trackedProperty->id);
                $dailyMetrics->where('tracked_property_id', $trackedProperty->id);
                $alertRules->where('tracked_property_id', $trackedProperty->id);
                $alertLogs->where('tracked_property_id', $trackedProperty->id);
            }

            $identities->whereBetween('last_seen_at', [$startAt, $endAt]);

            $metricTotals = (clone $dailyMetrics)
                ->selectRaw('COALESCE(SUM(sessions), 0) as sessions')
                ->selectRaw('COALESCE(SUM(events), 0) as events')
                ->selectRaw('COALESCE(SUM(conversions), 0) as conversions')
                ->toBase()
                ->first();

            $revenueRows = (clone $dailyMetrics)
                ->selectRaw('UPPER(currency) as currency')
                ->selectRaw('COALESCE(SUM(revenue_minor), 0) as revenue_minor')
                ->groupByRaw('UPPER(currency)')
                ->toBase()
                ->get();

            return [
                'tracked_properties' => $trackedProperties->count(),
                'identities' => $identities->count(),
                'sessions' => (int) ($metricTotals->sessions ?? 0),
                'events' => (int) ($metricTotals->events ?? 0),
                'conversions' => (int) ($metricTotals->conversions ?? 0),
                'active_alert_rules' => $alertRules->count(),
                'unread_alerts' => $alertLogs->count(),
                ...SignalReportRevenue::shape(
                    $trackedProperty?->id,
                    SignalReportRevenue::fromCurrencyRows($revenueRows->all()),
                    $trackedProperty instanceof TrackedProperty ? SignalCurrency::code($trackedProperty->currency) : null,
                ),
            ];
        });
    }

    /**
     * @return list<array{date:string,events:int,conversions:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>
     */
    public function trend(?TrackedProperty $trackedProperty = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array
    {
        return $this->withResolvedOwnerOrExplicitGlobal(function () use ($trackedProperty, $startAt, $endAt): array {
            [$startAt, $endAt] = $this->resolvePeriod($startAt, $endAt);

            $countQuery = SignalDailyMetric::query()
                ->whereBetween('date', [$startAt->toDateString(), $endAt->toDateString()])
                ->selectRaw('date as period')
                ->selectRaw('SUM(events) as events')
                ->selectRaw('SUM(conversions) as conversions')
                ->groupBy('date')
                ->orderBy('date');

            $revenueQuery = SignalDailyMetric::query()
                ->whereBetween('date', [$startAt->toDateString(), $endAt->toDateString()])
                ->selectRaw('date as period')
                ->selectRaw('UPPER(currency) as currency')
                ->selectRaw('COALESCE(SUM(revenue_minor), 0) as revenue_minor')
                ->groupBy('date')
                ->groupByRaw('UPPER(currency)')
                ->orderBy('date');

            if ($trackedProperty instanceof TrackedProperty) {
                $countQuery->where('tracked_property_id', $trackedProperty->id);
                $revenueQuery->where('tracked_property_id', $trackedProperty->id);
            }

            $revenueByPeriod = [];

            foreach ($revenueQuery->get() as $row) {
                $period = (string) $row->getAttribute('period');
                $currency = SignalCurrency::normalize($row->getAttribute('currency'));

                if ($currency === null) {
                    continue;
                }

                $revenueByPeriod[$period][$currency] = ($revenueByPeriod[$period][$currency] ?? 0)
                    + (int) $row->getAttribute('revenue_minor');
            }

            foreach ($revenueByPeriod as &$currencyTotals) {
                ksort($currencyTotals);
            }

            unset($currencyTotals);

            $propertyCurrency = $trackedProperty instanceof TrackedProperty
                ? SignalCurrency::code($trackedProperty->currency)
                : null;

            return $countQuery
                ->get()
                ->map(static function ($row) use ($revenueByPeriod, $trackedProperty, $propertyCurrency): array {
                    $period = (string) $row->getAttribute('period');

                    return [
                        'date' => $period,
                        'events' => (int) $row->getAttribute('events'),
                        'conversions' => (int) $row->getAttribute('conversions'),
                        ...SignalReportRevenue::shape($trackedProperty?->id, $revenueByPeriod[$period] ?? [], $propertyCurrency),
                    ];
                })
                ->values()
                ->all();
        });
    }

    public function availableReports(): array
    {
        return $this->reportRegistry->options();
    }

    public function report(string $type, ?string $trackedPropertyId = null, ?string $from = null, ?string $until = null): array
    {
        $report = $this->reportRegistry->get($type);

        return $report->summary($trackedPropertyId, $from, $until);
    }

    private function withResolvedOwnerOrExplicitGlobal(callable $callback): mixed
    {
        OwnerContext::assertResolvedOrExplicitGlobal(
            OwnerContext::resolve(),
            'Signals dashboard requires an owner context or explicit global context.',
        );

        return $callback();
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
