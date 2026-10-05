<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Helper for cross-tenant queries that intentionally bypass owner scoping.
 *
 * These queries stay safe by scoping to a resolved tracked property and
 * reapplying that property's owner tuple as defense in depth, so a forged
 * or reassigned property id can never leak rows across tenants.
 */
final class CrossTenantQuery
{
    public static function findExistingEvent(
        TrackedProperty $trackedProperty,
        string $ingestionSource,
        string $idempotencyKey,
    ): ?SignalEvent {
        /** @var SignalEvent|null */
        return SignalEvent::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('ingestion_source', $ingestionSource)
            ->where('idempotency_key', $idempotencyKey)
            ->where(function (Builder $query) use ($trackedProperty): void {
                self::applyPropertyOwnerTuple($query, $trackedProperty);
            })
            ->first();
    }

    public static function findSession(TrackedProperty $trackedProperty, string $sessionIdentifier): ?SignalSession
    {
        /** @var SignalSession|null */
        return self::sessionQuery($trackedProperty, $sessionIdentifier)->first();
    }

    /**
     * @return Builder<SignalIdentity>
     */
    public static function identityQuery(TrackedProperty $trackedProperty, string $externalId): Builder
    {
        return SignalIdentity::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('external_id', $externalId)
            ->where(function (Builder $query) use ($trackedProperty): void {
                self::applyPropertyOwnerTuple($query, $trackedProperty);
            });
    }

    /**
     * @return Builder<SignalSession>
     */
    public static function sessionQuery(TrackedProperty $trackedProperty, string $sessionIdentifier): Builder
    {
        return SignalSession::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('session_identifier', $sessionIdentifier)
            ->where(function (Builder $query) use ($trackedProperty): void {
                self::applyPropertyOwnerTuple($query, $trackedProperty);
            });
    }

    /**
     * @param  Builder<SignalEvent|SignalSession|SignalIdentity>  $query
     */
    private static function applyPropertyOwnerTuple(Builder $query, TrackedProperty $trackedProperty): void
    {
        if ($trackedProperty->owner_type === null || $trackedProperty->owner_id === null) {
            $query->whereNull('owner_type')->whereNull('owner_id');

            return;
        }

        $query->where('owner_type', $trackedProperty->owner_type)
            ->where('owner_id', $trackedProperty->owner_id);
    }
}
