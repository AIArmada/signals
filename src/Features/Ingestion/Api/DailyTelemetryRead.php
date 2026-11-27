<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

use Carbon\CarbonImmutable;
use Closure;

interface DailyTelemetryRead
{
    public function forProperty(CarbonImmutable $date, string $propertyId): DailyTelemetry;

    /** @return iterable<DailyTelemetry> */
    public function forRange(CarbonImmutable $from, CarbonImmutable $to): iterable;

    /** @param Closure(): int $callback */
    public function forOwners(Closure $callback): int;
}
