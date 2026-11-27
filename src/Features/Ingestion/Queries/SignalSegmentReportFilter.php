<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Queries;

use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\SignalIdentity;
use AIArmada\Signals\Features\Ingestion\Models\SignalSession;
use AIArmada\Signals\Features\Reporting\Api\ReportConfiguration;
use Illuminate\Database\Eloquent\Builder;

final class SignalSegmentReportFilter
{
    public function __construct(
        private readonly SignalEventConditionQueryService $conditionQueryService,
        private readonly ReportConfiguration $configuration,
    ) {}

    /**
     * @param  Builder<SignalEvent>  $query
     * @return Builder<SignalEvent>
     */
    public function applyToEventQuery(Builder $query, ?string $signalSegmentId): Builder
    {
        $segment = $this->configuration->segment($signalSegmentId);

        if ($signalSegmentId !== null && $signalSegmentId !== '' && $segment === null) {
            return $this->conditionQueryService->failClosed($query);
        }

        if ($segment === null) {
            return $query;
        }

        return $this->conditionQueryService->apply($query, $segment->conditions, $segment->matchType);
    }

    /**
     * @param  Builder<SignalSession>  $query
     * @return Builder<SignalSession>
     */
    public function applyToSessionQuery(Builder $query, ?string $signalSegmentId): Builder
    {
        $segment = $this->configuration->segment($signalSegmentId);

        if ($signalSegmentId !== null && $signalSegmentId !== '' && $segment === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($segment === null) {
            return $query;
        }

        return $query->whereHas('events', function (Builder $eventQuery) use ($segment): void {
            $this->conditionQueryService->apply($eventQuery, $segment->conditions, $segment->matchType);
        });
    }

    /**
     * @param  Builder<SignalIdentity>  $query
     * @return Builder<SignalIdentity>
     */
    public function applyToIdentityQuery(Builder $query, ?string $signalSegmentId): Builder
    {
        $segment = $this->configuration->segment($signalSegmentId);

        if ($signalSegmentId !== null && $signalSegmentId !== '' && $segment === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($segment === null) {
            return $query;
        }

        return $query->whereHas('events', function (Builder $eventQuery) use ($segment): void {
            $this->conditionQueryService->apply($eventQuery, $segment->conditions, $segment->matchType);
        });
    }
}
