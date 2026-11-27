<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

final readonly class EventMetric
{
    /** @param array<string, mixed> $eventFilters */
    public function __construct(
        public string $metricKey,
        public ?string $trackedPropertyId,
        public int $timeframeMinutes,
        public array $eventFilters,
        public ?string $revenueCurrency,
    ) {}
}
