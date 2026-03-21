<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SavedSignalReport;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AcquisitionReportService
{
    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return array{attributed_events:int,visitors:int,conversions:int,revenue_minor:int,campaigns:int}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $attributionModel = null, ?string $savedReportId = null): array
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $attributionModel, $savedReportId);
        $query = $this->baseQuery(
            $context['tracked_property_id'],
            $context['from'],
            $context['until'],
            $context['signal_segment_id'],
            $context['attribution_model'],
            $context['conversion_event_name'],
        );
        $campaignExpression = $this->dimensionExpression($query, 'campaign', $context['attribution_model'], $context['conversion_event_name']);

        return [
            'attributed_events' => (int) (clone $query)->count(),
            'visitors' => $this->distinctActorCount(clone $query),
            'conversions' => (int) (clone $query)->where('event_category', 'conversion')->count(),
            'revenue_minor' => (int) (clone $query)->sum('revenue_minor'),
            'campaigns' => (int) (clone $query)
                ->whereRaw("{$campaignExpression} IS NOT NULL")
                ->distinct(DB::raw($campaignExpression))
                ->count(DB::raw($campaignExpression)),
        ];
    }

    /**
     * @return Builder<SignalEvent>
     */
    public function getTableQuery(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $attributionModel = null, ?string $savedReportId = null): Builder
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $attributionModel, $savedReportId);
        $query = $this->baseQuery(
            $context['tracked_property_id'],
            $context['from'],
            $context['until'],
            $context['signal_segment_id'],
            $context['attribution_model'],
            $context['conversion_event_name'],
        );
        $sourceExpression = $this->dimensionExpression($query, 'source', $context['attribution_model'], $context['conversion_event_name']);
        $mediumExpression = $this->dimensionExpression($query, 'medium', $context['attribution_model'], $context['conversion_event_name']);
        $campaignExpression = $this->dimensionExpression($query, 'campaign', $context['attribution_model'], $context['conversion_event_name']);
        $contentExpression = $this->dimensionExpression($query, 'content', $context['attribution_model'], $context['conversion_event_name']);
        $termExpression = $this->dimensionExpression($query, 'term', $context['attribution_model'], $context['conversion_event_name']);
        $referrerExpression = $this->dimensionExpression($query, 'referrer', $context['attribution_model'], $context['conversion_event_name']);

        return $query
            ->select('tracked_property_id')
            ->selectRaw('MIN(id) as id')
            ->selectRaw("COALESCE({$sourceExpression}, 'direct') as acquisition_source")
            ->selectRaw("COALESCE({$mediumExpression}, 'direct') as acquisition_medium")
            ->selectRaw("COALESCE({$campaignExpression}, '(none)') as acquisition_campaign")
            ->selectRaw("COALESCE({$contentExpression}, '(none)') as acquisition_content")
            ->selectRaw("COALESCE({$termExpression}, '(none)') as acquisition_term")
            ->selectRaw("COALESCE({$referrerExpression}, '(direct)') as acquisition_referrer")
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('COUNT(DISTINCT COALESCE(signal_identity_id, signal_session_id, id)) as visitors')
            ->selectRaw("SUM(CASE WHEN event_category = 'conversion' THEN 1 ELSE 0 END) as conversions")
            ->selectRaw('SUM(revenue_minor) as revenue_minor')
            ->selectRaw('MAX(occurred_at) as last_seen_at')
            ->with('trackedProperty')
            ->groupBy('tracked_property_id')
            ->groupByRaw($sourceExpression)
            ->groupByRaw($mediumExpression)
            ->groupByRaw($campaignExpression)
            ->groupByRaw($contentExpression)
            ->groupByRaw($termExpression)
            ->groupByRaw($referrerExpression);
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
            ->where('report_type', 'acquisition')
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function baseQuery(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, string $attributionModel, string $conversionEventName): Builder
    {
        return $this->segmentReportFilter->applyToEventQuery(SignalEvent::query(), $signalSegmentId)
            ->when(
                $attributionModel !== SavedSignalReportDefinition::ATTRIBUTION_MODEL_EVENT,
                fn (Builder $query): Builder => $query->where('event_name', $conversionEventName)
            )
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('source')
                    ->orWhereNotNull('medium')
                    ->orWhereNotNull('campaign')
                    ->orWhereNotNull('content')
                    ->orWhereNotNull('term')
                    ->orWhereNotNull('referrer')
                    ->orWhere('event_category', 'page_view');
            })
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

    private function dimensionExpression(Builder $query, string $field, string $attributionModel, string $conversionEventName): string
    {
        if ($attributionModel === SavedSignalReportDefinition::ATTRIBUTION_MODEL_EVENT) {
            return $field;
        }

        $table = $query->getModel()->getTable();
        $subqueryTable = $table . ' as attribution_events';
        $direction = $attributionModel === SavedSignalReportDefinition::ATTRIBUTION_MODEL_FIRST_TOUCH ? 'asc' : 'desc';
        $outerActorExpression = $this->actorExpression($table);
        $innerActorExpression = $this->actorExpression('attribution_events');

        return sprintf(
            '(select attribution_events.%1$s from %2$s where attribution_events.tracked_property_id = %3$s.tracked_property_id and %4$s = %5$s and attribution_events.occurred_at <= %3$s.occurred_at and attribution_events.%1$s is not null order by attribution_events.occurred_at %6$s, attribution_events.id %6$s limit 1)',
            $field,
            $subqueryTable,
            $table,
            $innerActorExpression,
            $outerActorExpression,
            $direction,
        );
    }

    private function actorExpression(string $table): string
    {
        return sprintf('COALESCE(%1$s.signal_identity_id, %1$s.signal_session_id, %1$s.id)', $table);
    }

    /**
     * @return array{tracked_property_id:string|null,from:string|null,until:string|null,signal_segment_id:string|null,attribution_model:string,conversion_event_name:string}
     */
    private function resolveReportContext(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, ?string $attributionModel, ?string $savedReportId): array
    {
        $savedReport = $this->resolveSavedReport($savedReportId);
        $filters = $savedReport?->normalizedFilters() ?? [];
        $settings = $savedReport?->normalizedSettings() ?? [];

        return [
            'tracked_property_id' => $trackedPropertyId ?? $savedReport?->tracked_property_id,
            'from' => $from ?? ($filters['date_from'] ?? null),
            'until' => $until ?? ($filters['date_to'] ?? null),
            'signal_segment_id' => $signalSegmentId ?? $savedReport?->signal_segment_id,
            'attribution_model' => filled($attributionModel)
                ? $attributionModel
                : SavedSignalReportDefinition::attributionModel($settings),
            'conversion_event_name' => SavedSignalReportDefinition::conversionEventName($settings),
        ];
    }

    private function resolveSavedReport(?string $savedReportId): ?SavedSignalReport
    {
        if ($savedReportId === null || $savedReportId === '') {
            return null;
        }

        return SavedSignalReport::query()
            ->forOwner()
            ->where('report_type', 'acquisition')
            ->where('is_active', true)
            ->whereKey($savedReportId)
            ->first();
    }

    /**
     * @param  Builder<SignalEvent>  $query
     */
    private function distinctActorCount(Builder $query): int
    {
        return (int) $query
            ->selectRaw('COUNT(DISTINCT COALESCE(signal_identity_id, signal_session_id, id)) as aggregate')
            ->value('aggregate');
    }
}
