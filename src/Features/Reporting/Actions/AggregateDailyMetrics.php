<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Ingestion\Api\DailyTelemetry;
use AIArmada\Signals\Features\Ingestion\Api\DailyTelemetryRead;
use AIArmada\Signals\Features\Reporting\Models\SignalDailyMetric;
use AIArmada\Signals\Support\SignalCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AggregateDailyMetrics
{
    private const int BATCH_SIZE = 100;

    public function __construct(private DailyTelemetryRead $telemetry) {}

    public function aggregateForDate(CarbonImmutable $date, string $propertyId): SignalDailyMetric
    {
        $row = $this->telemetry->forProperty($date, $propertyId);
        $this->persistBatch([$row]);

        return SignalDailyMetric::query()->forOwner()
            ->where('tracked_property_id', $propertyId)
            ->where('date', $date->toDateString())
            ->firstOrFail();
    }

    public function aggregateRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $count = 0;
        $batch = [];

        foreach ($this->telemetry->forRange($from, $to) as $row) {
            $batch[] = $row;
            $count++;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->persistBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->persistBatch($batch);
        }

        return $count;
    }

    public function aggregateForOwners(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->telemetry->forOwners(fn (): int => $this->aggregateRange($from, $to));
    }

    /** @param list<DailyTelemetry> $rows */
    private function persistBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->assertOwnerParity($rows);
        $byKey = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->date)->toDateString();
            $currency = SignalCurrency::code($row->currency);
            $byKey[$row->propertyId . '|' . $date] = [
                'tracked_property_id' => $row->propertyId,
                'date' => $date,
                'owner_type' => $row->ownerType,
                'owner_id' => $row->ownerId,
                'unique_identities' => $row->identities,
                'sessions' => $row->sessions,
                'bounced_sessions' => $row->bouncedSessions,
                'page_views' => $row->pageViews,
                'events' => $row->events,
                'conversions' => $row->conversions,
                'revenue_minor' => $row->revenueMinor,
                'currency' => $currency,
            ];
        }

        $now = CarbonImmutable::now();
        $values = [];

        foreach ($byKey as $row) {
            $values[] = [
                'id' => (string) Str::uuid(),
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SignalDailyMetric::query()->upsert(
            $values,
            ['tracked_property_id', 'date'],
            ['unique_identities', 'sessions', 'bounced_sessions', 'page_views', 'events', 'conversions', 'revenue_minor', 'currency', 'updated_at'],
        );
    }

    /** @param list<DailyTelemetry> $rows */
    private function assertOwnerParity(array $rows): void
    {
        if (! SignalDailyMetric::ownerScopeConfig()->enabled) {
            return;
        }

        $owner = OwnerContext::resolve();
        OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Daily metric aggregation requires owner context.');

        foreach ($rows as $row) {
            if ($owner === null) {
                if ($row->ownerType !== null || $row->ownerId !== null) {
                    throw new InvalidArgumentException('Global daily metric aggregation cannot persist owner-scoped telemetry.');
                }

                continue;
            }

            if ($row->ownerType !== $owner->getMorphClass() || (string) $row->ownerId !== (string) $owner->getKey()) {
                throw new InvalidArgumentException('Daily metric aggregation cannot persist telemetry for another owner.');
            }
        }
    }
}
