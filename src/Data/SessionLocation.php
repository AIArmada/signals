<?php

declare(strict_types=1);

namespace AIArmada\Signals\Data;

final readonly class SessionLocation
{
    public function __construct(
        public string $sessionId,
        public string $trackedPropertyId,
        public ?string $ownerType,
        public string | int | null $ownerId,
        public float $latitude,
        public float $longitude,
        public string $provider,
        public GeocodingResult $result,
    ) {}
}
