<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

use Carbon\CarbonImmutable;

final readonly class IngestedSignalEvent
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public string $id,
        public string $trackedPropertyId,
        public string $eventName,
        public string $eventCategory,
        public CarbonImmutable $occurredAt,
        public ?string $idempotencyKey,
        public ?string $sourceEventId,
        public array $properties,
        public int $revenueMinor,
        public string $currency,
    ) {}
}
