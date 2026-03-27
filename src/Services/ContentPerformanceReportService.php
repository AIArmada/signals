<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SavedSignalReport;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

final class ContentPerformanceReportService
{
    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return array{paths:int,views:int,conversions:int,revenue_minor:int,avg_conversion_rate:float}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array
    {
        $rows = $this->rows($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);

        return [
            'paths' => count($rows),
            'views' => array_sum(array_column($rows, 'views')),
            'conversions' => array_sum(array_column($rows, 'conversions')),
            'revenue_minor' => array_sum(array_column($rows, 'revenue_minor')),
            'avg_conversion_rate' => round(count($rows) > 0 ? array_sum(array_column($rows, 'conversion_rate')) / count($rows) : 0, 2),
        ];
    }

    /**
     * @return Builder<SignalEvent>
     */
    public function getTableQuery(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): Builder
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);
        $dimension = $context['breakdown_dimension'];

        return $this->baseQuery($context['tracked_property_id'], $context['from'], $context['until'], $context['signal_segment_id'])
            ->select('tracked_property_id')
            ->selectRaw('MIN(CAST(id AS text)) as id')
            ->selectRaw('MIN(path) as content_path')
            ->selectRaw('MAX(url) as content_url')
            ->selectRaw("'{$this->escapeSqlLiteral($this->getBreakdownLabel($savedReportId))}' as content_breakdown_label")
            ->selectRaw($this->breakdownValueExpression($dimension) . ' as content_breakdown_value')
            ->selectRaw("SUM(CASE WHEN event_category = 'page_view' THEN 1 ELSE 0 END) as views")
            ->selectRaw("SUM(CASE WHEN event_category = 'conversion' THEN 1 ELSE 0 END) as conversions")
            ->selectRaw('SUM(revenue_minor) as revenue_minor')
            ->selectRaw('COUNT(DISTINCT COALESCE(signal_identity_id, signal_session_id, id)) as visitors')
            ->selectRaw('MAX(occurred_at) as last_seen_at')
            ->with('trackedProperty')
            ->whereNotNull('path')
            ->groupBy('tracked_property_id')
            ->groupByRaw($this->groupByExpression($dimension));
    }

    /**
     * @return list<array{tracked_property_id:string,content_path:string,views:int,conversions:int,revenue_minor:int,visitors:int,conversion_rate:float}>
     */
    public function rows(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array
    {
        return $this->getTableQuery($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId)
            ->get()
            ->map(function (SignalEvent $row): array {
                $views = (int) ($row->views ?? 0);
                $conversions = (int) ($row->conversions ?? 0);

                return [
                    'tracked_property_id' => (string) $row->tracked_property_id,
                    'content_path' => (string) ($row->content_path ?? '/'),
                    'views' => $views,
                    'conversions' => $conversions,
                    'revenue_minor' => (int) ($row->revenue_minor ?? 0),
                    'visitors' => (int) ($row->visitors ?? 0),
                    'conversion_rate' => $views > 0 ? round(($conversions / $views) * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('views')
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
            ->where('report_type', 'content_performance')
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function getBreakdownLabel(?string $savedReportId = null): string
    {
        $savedReport = $this->resolveSavedReport($savedReportId);
        $dimension = SavedSignalReportDefinition::contentBreakdownDimension($savedReport?->normalizedSettings());

        return SavedSignalReportDefinition::contentBreakdownDimensionOptions()[$dimension] ?? 'Path';
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function baseQuery(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId): Builder
    {
        return $this->segmentReportFilter->applyToEventQuery(SignalEvent::query(), $signalSegmentId)
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $trackedPropertyId)
            )
            ->when(
                filled($from),
                fn (Builder $query): Builder => $query->whereDate('occurred_at', '>=', (string) $from)
            )
            ->when(
                filled($until),
                fn (Builder $query): Builder => $query->whereDate('occurred_at', '<=', (string) $until)
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
            'breakdown_dimension' => SavedSignalReportDefinition::contentBreakdownDimension($settings),
        ];
    }

    private function resolveSavedReport(?string $savedReportId): ?SavedSignalReport
    {
        if ($savedReportId === null || $savedReportId === '') {
            return null;
        }

        return SavedSignalReport::query()
            ->forOwner()
            ->where('report_type', 'content_performance')
            ->where('is_active', true)
            ->whereKey($savedReportId)
            ->first();
    }

    private function breakdownValueExpression(string $dimension): string
    {
        return match ($dimension) {
            'source' => "COALESCE(source, 'direct')",
            'medium' => "COALESCE(medium, 'direct')",
            'campaign' => "COALESCE(campaign, '(none)')",
            'referrer' => "COALESCE(referrer, '(direct)')",
            default => "COALESCE(path, '/')",
        };
    }

    private function groupByExpression(string $dimension): string
    {
        return match ($dimension) {
            'source' => 'source',
            'medium' => 'medium',
            'campaign' => 'campaign',
            'referrer' => 'referrer',
            default => 'path',
        };
    }

    private function escapeSqlLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
