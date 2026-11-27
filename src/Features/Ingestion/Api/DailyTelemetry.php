<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

final readonly class DailyTelemetry
{
    public function __construct(
        public string $propertyId,
        public string $date,
        public ?string $ownerType,
        public ?string $ownerId,
        public string $currency,
        public int $identities,
        public int $sessions,
        public int $bouncedSessions,
        public int $pageViews,
        public int $events,
        public int $conversions,
        public int $revenueMinor,
    ) {}
}
