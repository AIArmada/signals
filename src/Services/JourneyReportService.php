<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SavedSignalReport;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

final class JourneyReportService
{
    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return array{sessions:int,unique_entry_paths:int,unique_exit_paths:int,bounced_sessions:int,avg_duration_seconds:float}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);
        $query = $this->baseQuery($context['tracked_property_id'], $context['from'], $context['until'], $context['signal_segment_id']);

        return [
            'sessions' => (int) (clone $query)->count(),
            'unique_entry_paths' => (int) (clone $query)
                ->whereNotNull('entry_path')
                ->distinct('entry_path')
                ->count('entry_path'),
            'unique_exit_paths' => (int) (clone $query)
                ->whereNotNull('exit_path')
                ->distinct('exit_path')
                ->count('exit_path'),
            'bounced_sessions' => (int) (clone $query)->where('is_bounce', true)->count(),
            'avg_duration_seconds' => round((float) ((clone $query)->avg('duration_seconds') ?? 0), 2),
        ];
    }

    /**
     * @return Builder<SignalSession>
     */
    public function getTableQuery(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): Builder
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);
        $dimension = $context['breakdown_dimension'];

        return $this->baseQuery($context['tracked_property_id'], $context['from'], $context['until'], $context['signal_segment_id'])
            ->select('tracked_property_id')
            ->selectRaw('MIN(id) as id')
            ->selectRaw('MIN(entry_path) as journey_entry_path')
            ->selectRaw('MIN(exit_path) as journey_exit_path')
            ->selectRaw("'{$this->escapeSqlLiteral($this->getBreakdownLabel($savedReportId))}' as journey_breakdown_label")
            ->selectRaw($this->breakdownValueExpression($dimension) . ' as journey_breakdown_value')
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) as bounced_sessions')
            ->selectRaw('AVG(duration_seconds) as avg_duration_seconds')
            ->selectRaw('MAX(started_at) as last_started_at')
            ->with('trackedProperty')
            ->groupBy('tracked_property_id')
            ->groupByRaw($this->groupByExpression($dimension));
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
            ->where('report_type', 'journeys')
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function getBreakdownLabel(?string $savedReportId = null): string
    {
        $savedReport = $this->resolveSavedReport($savedReportId);
        $dimension = SavedSignalReportDefinition::journeyBreakdownDimension($savedReport?->normalizedSettings());

        return SavedSignalReportDefinition::journeyBreakdownDimensionOptions()[$dimension] ?? 'Path Pair';
    }

    /**
     * @return Builder<SignalSession>
     */
    private function baseQuery(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId): Builder
    {
        return $this->segmentReportFilter->applyToSessionQuery(SignalSession::query(), $signalSegmentId)
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $trackedPropertyId)
            )
            ->when(
                filled($from),
                fn (Builder $query): Builder => $query->whereDate('started_at', '>=', (string) $from)
            )
            ->when(
                filled($until),
                fn (Builder $query): Builder => $query->whereDate('started_at', '<=', (string) $until)
            );
    }

    /**
     * @return array{tracked_property_id:string|null,from:string|null,until:string|null,signal_segment_id:string|null,breakdown_dimension:string}
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
            'breakdown_dimension' => SavedSignalReportDefinition::journeyBreakdownDimension($settings),
        ];
    }

    private function resolveSavedReport(?string $savedReportId): ?SavedSignalReport
    {
        if ($savedReportId === null || $savedReportId === '') {
            return null;
        }

        return SavedSignalReport::query()
            ->forOwner()
            ->where('report_type', 'journeys')
            ->where('is_active', true)
            ->whereKey($savedReportId)
            ->first();
    }

    private function breakdownValueExpression(string $dimension): string
    {
        return match ($dimension) {
            'entry_path' => "COALESCE(entry_path, '(unknown)')",
            'exit_path' => "COALESCE(exit_path, '(unknown)')",
            'country' => "COALESCE(country, '(unknown)')",
            'device_type' => "COALESCE(device_type, '(unknown)')",
            'browser' => "COALESCE(browser, '(unknown)')",
            'os' => "COALESCE(os, '(unknown)')",
            'utm_source' => "COALESCE(utm_source, 'direct')",
            'utm_medium' => "COALESCE(utm_medium, 'direct')",
            'utm_campaign' => "COALESCE(utm_campaign, '(none)')",
            default => "COALESCE(entry_path, '(unknown)')",
        };
    }

    private function groupByExpression(string $dimension): string
    {
        return match ($dimension) {
            'entry_path' => 'entry_path',
            'exit_path' => 'exit_path',
            'country' => 'country',
            'device_type' => 'device_type',
            'browser' => 'browser',
            'os' => 'os',
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            default => 'entry_path, exit_path',
        };
    }

    private function escapeSqlLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
