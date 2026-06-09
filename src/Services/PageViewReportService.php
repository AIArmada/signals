<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PageViewReportService
{
    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return Builder<SignalEvent>
     */
    public function getTableQuery(?string $signalSegmentId = null): Builder
    {
        return $this->segmentReportFilter->applyToEventQuery(SignalEvent::query(), $signalSegmentId)
            ->select('tracked_property_id')
            ->selectRaw('MIN(CAST(id AS text)) as id')
            ->selectRaw("COALESCE(path, '/') as page_path")
            ->selectRaw('MAX(url) as page_url')
            ->selectRaw('COUNT(*) as views')
            ->selectRaw('COUNT(DISTINCT COALESCE(signal_identity_id, signal_session_id, id)) as visitors')
            ->selectRaw('MIN(occurred_at) as first_seen_at')
            ->selectRaw('MAX(occurred_at) as last_seen_at')
            ->where('event_category', 'page_view')
            ->with('trackedProperty')
            ->groupBy('tracked_property_id')
            ->groupBy('path');
    }

    /**
     * @return array{total_page_views: int, total_visitors: int, total_pages: int}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null): array
    {
        $query = $this->baseQuery($trackedPropertyId, $from, $until);

        return [
            'total_page_views' => (int) (clone $query)->count(),
            'total_visitors' => (int) (clone $query)->distinct()->count('signal_identity_id'),
            'total_pages' => (int) (clone $query)->distinct()->count(DB::raw("COALESCE(path, '/')")),
        ];
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function baseQuery(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null): Builder
    {
        return $this->segmentReportFilter->applyToEventQuery(SignalEvent::query(), null)
            ->where('event_category', 'page_view')
            ->when($trackedPropertyId !== null, fn (Builder $q): Builder => $q->where('tracked_property_id', $trackedPropertyId))
            ->when($from !== null, fn (Builder $q): Builder => $q->where('occurred_at', '>=', $from))
            ->when($until !== null, fn (Builder $q): Builder => $q->where('occurred_at', '<=', $until));
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
}
