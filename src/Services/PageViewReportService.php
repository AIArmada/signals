<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

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
            ->selectRaw('MIN(id) as id')
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
