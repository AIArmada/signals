<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Extensions;

use AIArmada\Signals\Contracts\ReportInterface;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Queries\SignalSegmentReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class PageViewReportService implements ReportInterface
{
    public function type(): string
    {
        return 'page_view';
    }

    public function name(): string
    {
        return 'Page Views';
    }

    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return Builder<SignalEvent>
     */
    public function getTableQuery(
        ?string $signalSegmentId = null,
        ?string $trackedPropertyId = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null,
    ): Builder {
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
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $trackedPropertyId)
            )
            ->when(
                $from instanceof CarbonImmutable,
                fn (Builder $query): Builder => $query->where('occurred_at', '>=', $from->startOfDay())
            )
            ->when(
                $until instanceof CarbonImmutable,
                fn (Builder $query): Builder => $query->where('occurred_at', '<', $until->startOfDay()->addDay())
            )
            ->with('trackedProperty')
            ->groupBy('tracked_property_id')
            ->groupByRaw("COALESCE(path, '/')");
    }

    /**
     * @return array{total_page_views: int, total_visitors: int, total_pages: int}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null): array
    {
        $row = $this->baseQuery($trackedPropertyId, $from, $until)
            ->toBase()
            ->selectRaw('COUNT(*) as total_page_views')
            ->selectRaw('COUNT(DISTINCT COALESCE(signal_identity_id, signal_session_id, id)) as total_visitors')
            ->selectRaw("COUNT(DISTINCT COALESCE(path, '/')) as total_pages")
            ->first();

        return [
            'total_page_views' => (int) ($row->total_page_views ?? 0),
            'total_visitors' => (int) ($row->total_visitors ?? 0),
            'total_pages' => (int) ($row->total_pages ?? 0),
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
}
