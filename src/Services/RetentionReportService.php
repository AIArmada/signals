<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SavedSignalReport;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\TrackedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class RetentionReportService
{
    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return array{cohorts:int,identities:int,windows:list<array{days:int,retained:int,avg_retention_rate:float}>}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);
        $cohorts = $this->cohorts($context['tracked_property_id'], $context['from'], $context['until'], $context['signal_segment_id'], $context['retention_windows']);
        $windows = array_map(function (int $days) use ($cohorts): array {
            $retained = (int) $cohorts->sum(function (array $row) use ($days): int {
                foreach ($row['windows'] as $window) {
                    if ($window['days'] === $days) {
                        return $window['retained'];
                    }
                }

                return 0;
            });
            $avgRetentionRate = $cohorts->avg(function (array $row) use ($days): float {
                foreach ($row['windows'] as $window) {
                    if ($window['days'] === $days) {
                        return $window['retention_rate'];
                    }
                }

                return 0.0;
            }) ?? 0.0;

            return [
                'days' => $days,
                'retained' => $retained,
                'avg_retention_rate' => round((float) $avgRetentionRate, 2),
            ];
        }, $context['retention_windows']);

        return [
            'cohorts' => $cohorts->count(),
            'identities' => (int) $cohorts->sum('cohort_size'),
            'windows' => $windows,
        ];
    }

    /**
     * @return list<array{cohort_date:string,cohort_size:int,windows:list<array{days:int,retained:int,retention_rate:float}>}>
     */
    public function rows(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);

        return $this->cohorts($context['tracked_property_id'], $context['from'], $context['until'], $context['signal_segment_id'], $context['retention_windows'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getTrackedPropertyOptions(): array
    {
        return TrackedProperty::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getSavedReportOptions(): array
    {
        return SavedSignalReport::query()
            ->forOwner()
            ->where('report_type', 'retention')
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $retentionWindows
     * @return Collection<int, array{cohort_date:string,cohort_size:int,windows:list<array{days:int,retained:int,retention_rate:float}>}>
     */
    private function cohorts(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, array $retentionWindows): Collection
    {
        return $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId)
            ->get()
            ->groupBy(function (SignalIdentity $identity): string {
                return $identity->first_seen_at?->toDateString() ?? 'unknown';
            })
            ->map(function (Collection $identities, string $cohortDate) use ($retentionWindows): array {
                $cohortSize = $identities->count();
                $windows = array_map(function (int $days) use ($identities, $cohortSize): array {
                    $retained = $identities->filter(function (SignalIdentity $identity) use ($days): bool {
                        return $this->isRetainedAfterDays($identity, $days);
                    })->count();

                    return [
                        'days' => $days,
                        'retained' => $retained,
                        'retention_rate' => $this->rate($retained, $cohortSize),
                    ];
                }, $retentionWindows);

                return [
                    'cohort_date' => $cohortDate,
                    'cohort_size' => $cohortSize,
                    'windows' => $windows,
                ];
            })
            ->sortByDesc('cohort_date');
    }

    private function isRetainedAfterDays(SignalIdentity $identity, int $days): bool
    {
        if (! $identity->first_seen_at instanceof CarbonImmutable || ! $identity->last_seen_at instanceof CarbonImmutable) {
            return false;
        }

        return $identity->last_seen_at->greaterThanOrEqualTo($identity->first_seen_at->addDays($days));
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator === 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    /**
     * @return Builder<SignalIdentity>
     */
    private function baseQuery(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId): Builder
    {
        return $this->segmentReportFilter->applyToIdentityQuery(SignalIdentity::query(), $signalSegmentId)
            ->whereNotNull('first_seen_at')
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $trackedPropertyId)
            )
            ->when(
                filled($from),
                fn (Builder $query): Builder => $query->whereDate('first_seen_at', '>=', (string) $from)
            )
            ->when(
                filled($until),
                fn (Builder $query): Builder => $query->whereDate('first_seen_at', '<=', (string) $until)
            )
            ->orderByDesc('first_seen_at');
    }

    /**
     * @return array{tracked_property_id:string|null,from:string|null,until:string|null,signal_segment_id:string|null,retention_windows:list<int>}
     */
    private function resolveReportContext(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, ?string $savedReportId): array
    {
        $savedReport = $this->resolveSavedReport($savedReportId);
        $filters = $savedReport?->normalizedFilters() ?? [];
        $settings = $savedReport?->normalizedSettings() ?? [];

        return [
            'tracked_property_id' => $trackedPropertyId ?? $savedReport?->tracked_property_id,
            'from' => $from ?? ($filters['date_from'] ?? null),
            'until' => $until ?? ($filters['date_to'] ?? null),
            'signal_segment_id' => $signalSegmentId ?? $savedReport?->signal_segment_id,
            'retention_windows' => SavedSignalReportDefinition::retentionWindows($settings),
        ];
    }

    private function resolveSavedReport(?string $savedReportId): ?SavedSignalReport
    {
        if ($savedReportId === null || $savedReportId === '') {
            return null;
        }

        return SavedSignalReport::query()
            ->forOwner()
            ->where('report_type', 'retention')
            ->where('is_active', true)
            ->whereKey($savedReportId)
            ->first();
    }
}
