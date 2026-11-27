<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\AlertSummary;
use AIArmada\Signals\Features\Ingestion\Api\TelemetryRead;
use AIArmada\Signals\Features\Reporting\Api\DashboardRead;
use AIArmada\Signals\Features\Reporting\Models\SignalDailyMetric;
use AIArmada\Signals\Support\SignalCurrency;
use AIArmada\Signals\Support\SignalReportRevenue;
use Carbon\CarbonImmutable;

final class SignalsDashboardService implements DashboardRead
{
    public function __construct(
        private readonly ReportRegistry $reportRegistry,
        private readonly AlertSummary $alertSummary,
        private readonly TelemetryRead $telemetry,
    ) {}

    /**
     * @return array{tracked_properties:int,identities:int,sessions:int,events:int,conversions:int,active_alert_rules:int,unread_alerts:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array
    {
        return $this->withResolvedOwnerOrExplicitGlobal(function () use ($trackedPropertyId, $startAt, $endAt): array {
            $propertyCurrency = $trackedPropertyId === null ? null : $this->telemetry->requirePropertyCurrency($trackedPropertyId);

            [$startAt, $endAt] = $this->resolvePeriod($startAt, $endAt);

            $dailyMetrics = SignalDailyMetric::query()->whereBetween('date', [$startAt->toDateString(), $endAt->toDateString()]);

            if ($trackedPropertyId !== null) {
                $dailyMetrics->where('tracked_property_id', $trackedPropertyId);
            }

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
                ...$this->telemetry->counts($trackedPropertyId, $startAt, $endAt),
                'sessions' => (int) ($metricTotals->sessions ?? 0),
                'events' => (int) ($metricTotals->events ?? 0),
                'conversions' => (int) ($metricTotals->conversions ?? 0),
                ...$this->alertSummary->counts($trackedPropertyId),
                ...SignalReportRevenue::shape(
                    $trackedPropertyId,
                    SignalReportRevenue::fromCurrencyRows($revenueRows->all()),
                    $propertyCurrency,
                ),
            ];
        });
    }

    /**
     * @return list<array{date:string,events:int,conversions:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>
     */
    public function trend(?string $trackedPropertyId = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array
    {
        return $this->withResolvedOwnerOrExplicitGlobal(function () use ($trackedPropertyId, $startAt, $endAt): array {
            $propertyCurrency = $trackedPropertyId === null ? null : $this->telemetry->requirePropertyCurrency($trackedPropertyId);

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

            if ($trackedPropertyId !== null) {
                $countQuery->where('tracked_property_id', $trackedPropertyId);
                $revenueQuery->where('tracked_property_id', $trackedPropertyId);
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

            return $countQuery
                ->get()
                ->map(static function ($row) use ($revenueByPeriod, $trackedPropertyId, $propertyCurrency): array {
                    $period = (string) $row->getAttribute('period');

                    return [
                        'date' => $period,
                        'events' => (int) $row->getAttribute('events'),
                        'conversions' => (int) $row->getAttribute('conversions'),
                        ...SignalReportRevenue::shape($trackedPropertyId, $revenueByPeriod[$period] ?? [], $propertyCurrency),
                    ];
                })
                ->values()
                ->all();
        });
    }

    /** @return array<string, string> */
    public function availableReports(): array
    {
        return $this->reportRegistry->options();
    }

    /** @return array<string, mixed> */
    public function report(string $type, ?string $trackedPropertyId = null, ?string $from = null, ?string $until = null): array
    {
        return $this->withResolvedOwnerOrExplicitGlobal(function () use ($type, $trackedPropertyId, $from, $until): array {
            if ($trackedPropertyId !== null) {
                $this->telemetry->requirePropertyCurrency($trackedPropertyId);
            }

            return $this->reportRegistry->get($type)->summary($trackedPropertyId, $from, $until);
        });
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
