<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Queries;

use AIArmada\Signals\Features\Ingestion\Api\EventPropertyLookup;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use RuntimeException;

final readonly class FindEventProperty implements EventPropertyLookup
{
    public function propertyId(string $eventId): ?string
    {
        $event = SignalEvent::query()->forOwner()->whereKey($eventId)->first(['tracked_property_id']);

        if ($event !== null) {
            return (string) $event->tracked_property_id;
        }

        if (SignalEvent::query()->withoutOwnerScope()->whereKey($eventId)->exists()) {
            throw new RuntimeException('Signal event owner context mismatch.');
        }

        return null;
    }
}
