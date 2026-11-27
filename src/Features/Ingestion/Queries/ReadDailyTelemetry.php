<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Queries;

use AIArmada\CommerceSupport\Support\OwnerBatchRunner;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Ingestion\Api\DailyTelemetry;
use AIArmada\Signals\Features\Ingestion\Api\DailyTelemetryRead;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\SignalSession;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use AIArmada\Signals\Support\SignalCurrency;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

final readonly class ReadDailyTelemetry implements DailyTelemetryRead
{
    public function forProperty(CarbonImmutable $date, string $propertyId): DailyTelemetry
    {
        $property = TrackedProperty::query()->forOwner()->whereKey($propertyId)->firstOrFail();

        return $this->readChunk($date, collect([$property]))[0];
    }

    public function forRange(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        foreach (TrackedProperty::query()->forOwner()->lazyById(100)->chunk(100) as $properties) {
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                yield from $this->readChunk($date, $properties->collect()->values());
            }
        }
    }

    public function forOwners(Closure $callback): int
    {
        if (OwnerContext::isExplicitGlobal()) {
            return $callback();
        }

        return (int) (new OwnerBatchRunner(TrackedProperty::class, ['enabled' => 'signals.owner.enabled']))->run($callback);
    }

    /**
     * @param  Collection<int, TrackedProperty>  $properties
     * @return list<DailyTelemetry>
     */
    private function readChunk(CarbonImmutable $date, Collection $properties): array
    {
        $ids = $properties->pluck('id')->all();
        $start = $date->startOfDay();
        $end = $start->addDay();

        $events = SignalEvent::query()->forOwner()
            ->whereIn('tracked_property_id', $ids)
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end);

        $counts = (clone $events)
            ->select('tracked_property_id')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw("SUM(CASE WHEN event_category = 'page_view' THEN 1 ELSE 0 END) as page_views")
            ->selectRaw("SUM(CASE WHEN event_category = 'conversion' THEN 1 ELSE 0 END) as conversions")
            ->selectRaw('COUNT(DISTINCT signal_identity_id) as unique_identities')
            ->groupBy('tracked_property_id')->toBase()->get()->keyBy('tracked_property_id');

        $revenue = (clone $events)
            ->select('tracked_property_id')
            ->selectRaw('UPPER(currency) as currency')
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) as revenue_minor')
            ->groupBy('tracked_property_id')->groupByRaw('UPPER(currency)')
            ->toBase()->get()->groupBy('tracked_property_id');

        $sessions = SignalSession::query()->forOwner()
            ->whereIn('tracked_property_id', $ids)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->select('tracked_property_id')
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced_sessions')
            ->groupBy('tracked_property_id')->toBase()->get()->keyBy('tracked_property_id');

        $rows = [];

        foreach ($properties as $property) {
            $currency = SignalCurrency::code($property->currency);
            $count = $counts->get($property->id);
            $session = $sessions->get($property->id);
            $amount = $revenue->get($property->id)?->first(
                static fn ($row): bool => SignalCurrency::normalize($row->currency) === $currency,
            );

            $rows[] = new DailyTelemetry(
                propertyId: $property->id,
                date: $date->toDateString(),
                ownerType: $property->owner_type,
                ownerId: $property->owner_id === null ? null : (string) $property->owner_id,
                currency: $currency,
                identities: (int) ($count->unique_identities ?? 0),
                sessions: (int) ($session->sessions ?? 0),
                bouncedSessions: (int) ($session->bounced_sessions ?? 0),
                pageViews: (int) ($count->page_views ?? 0),
                events: (int) ($count->events ?? 0),
                conversions: (int) ($count->conversions ?? 0),
                revenueMinor: (int) ($amount->revenue_minor ?? 0),
            );
        }

        return $rows;
    }
}
