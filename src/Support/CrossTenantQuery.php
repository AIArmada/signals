<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Helper for cross-tenant queries that intentionally bypass owner scoping.
 *
 * These queries are safe because they scope by tracked_property_id, which
 * is itself scoped to a single tenant. The withoutOwnerScope() is needed
 * because SignalEvent and SignalSession use HasOwner but their natural
 * access pattern is always through a TrackedProperty.
 */
final class CrossTenantQuery
{
    public static function findExistingEvent(TrackedProperty $trackedProperty, string $idempotencyKey): ?SignalEvent
    {
        /** @var SignalEvent|null */
        return SignalEvent::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public static function findSession(TrackedProperty $trackedProperty, string $sessionIdentifier): ?SignalSession
    {
        /** @var SignalSession|null */
        return SignalSession::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('session_identifier', $sessionIdentifier)
            ->first();
    }

    /**
     * @return Builder<SignalSession>
     */
    public static function sessionQuery(TrackedProperty $trackedProperty, string $sessionIdentifier): Builder
    {
        return SignalSession::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('session_identifier', $sessionIdentifier);
    }
}
