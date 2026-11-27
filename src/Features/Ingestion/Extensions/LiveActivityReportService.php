<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Extensions;

use AIArmada\Signals\Contracts\ReportInterface;
use AIArmada\Signals\Features\Ingestion\Api\LiveActivityReportRead;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Queries\SignalSegmentReportFilter;
use AIArmada\Signals\Support\SignalReportRevenue;
use Illuminate\Database\Eloquent\Builder;

final class LiveActivityReportService implements LiveActivityReportRead, ReportInterface
{
    public function type(): string
    {
        return 'live_activity';
    }

    public function name(): string
    {
        return 'Live Activity';
    }

    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return array{events:int,page_views:int,conversions:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array
    {
        $query = $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId);

        return [
            'events' => (int) (clone $query)->count(),
            'page_views' => (int) (clone $query)->where('event_category', 'page_view')->count(),
            'conversions' => (int) (clone $query)->where('event_category', 'conversion')->count(),
            ...SignalReportRevenue::shape($trackedPropertyId, SignalReportRevenue::byCurrency(clone $query)),
        ];
    }

    /**
     * @return Builder<SignalEvent>
     */
    public function getTableQuery(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): Builder
    {
        return $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId)
            ->with(['trackedProperty', 'identity', 'session'])
            ->orderByDesc('occurred_at');
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
}
