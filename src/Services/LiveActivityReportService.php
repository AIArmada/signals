<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

final class LiveActivityReportService
{
    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return array{events:int,page_views:int,conversions:int,revenue_minor:int}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array
    {
        $query = $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId);

        return [
            'events' => (int) (clone $query)->count(),
            'page_views' => (int) (clone $query)->where('event_category', 'page_view')->count(),
            'conversions' => (int) (clone $query)->where('event_category', 'conversion')->count(),
            'revenue_minor' => (int) (clone $query)->sum('revenue_minor'),
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
