<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface EventPropertyLookup
{
    /** Returns null for a deleted event; rejects events outside the current owner. */
    public function propertyId(string $eventId): ?string;
}
