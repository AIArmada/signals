<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

final readonly class TrackedPropertyDeleting
{
    public function __construct(
        public string $propertyId,
        public ?string $ownerType,
        public string | int | null $ownerId,
    ) {}
}
