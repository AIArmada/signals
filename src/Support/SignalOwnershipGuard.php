<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Signals\Models\SignalAlertLog;
use AIArmada\Signals\Models\SignalDailyMetric;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared write boundary for Signals child models.
 *
 * Telemetry integrity (referenced rows must exist, tracked_property_id
 * immutability, and same-property membership) always applies. Owner tenant
 * checks additionally apply when owner scoping is enabled; explicit global
 * context may only reference global-only rows.
 */
final class SignalOwnershipGuard
{
    public static function ownerScopingEnabled(): bool
    {
        return (bool) config('signals.owner.enabled', false);
    }

    public static function resolveOwnerOrFail(string $operation): ?Model
    {
        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal(
            $owner,
            sprintf(
                'Owner context is required to %s. Use OwnerContext::withOwner(null, ...) for explicit global access.',
                $operation,
            ),
        );

        return $owner;
    }

    public static function assertEventWrite(SignalEvent $event): void
    {
        self::assertValidIngestionSource($event);
        self::assertTrackedPropertyImmutable($event);

        if (! self::ownerScopingEnabled()) {
            self::assertEventSamePropertyUnscoped($event);

            return;
        }

        $owner = self::resolveOwnerOrFail('save signal events');
        $propertyId = (string) $event->tracked_property_id;

        self::findPropertyOrFail($propertyId, $owner);

        if ($event->signal_session_id !== null && $event->signal_session_id !== '') {
            $session = self::findOwnedOrFail(SignalSession::class, (string) $event->signal_session_id, $owner);

            self::assertSameProperty((string) $session->tracked_property_id, $propertyId, 'signal_session_id');
        }

        if ($event->signal_identity_id !== null && $event->signal_identity_id !== '') {
            $identity = self::findOwnedOrFail(SignalIdentity::class, (string) $event->signal_identity_id, $owner);

            self::assertSameProperty((string) $identity->tracked_property_id, $propertyId, 'signal_identity_id');
        }
    }

    public static function assertSessionWrite(SignalSession $session): void
    {
        self::assertTrackedPropertyImmutable($session);

        if (! self::ownerScopingEnabled()) {
            self::assertSessionSamePropertyUnscoped($session);

            return;
        }

        $owner = self::resolveOwnerOrFail('save signal sessions');
        $propertyId = (string) $session->tracked_property_id;

        self::findPropertyOrFail($propertyId, $owner);

        if ($session->signal_identity_id !== null && $session->signal_identity_id !== '') {
            $identity = self::findOwnedOrFail(SignalIdentity::class, (string) $session->signal_identity_id, $owner);

            self::assertSameProperty((string) $identity->tracked_property_id, $propertyId, 'signal_identity_id');
        }
    }

    public static function assertIdentityWrite(SignalIdentity $identity): void
    {
        self::assertTrackedPropertyImmutable($identity);

        if (! self::ownerScopingEnabled()) {
            self::findPropertyUnscopedOrFail((string) $identity->tracked_property_id);

            return;
        }

        $owner = self::resolveOwnerOrFail('save signal identities');

        self::findPropertyOrFail((string) $identity->tracked_property_id, $owner);
    }

    public static function assertDailyMetricWrite(SignalDailyMetric $metric): void
    {
        if (! self::ownerScopingEnabled()) {
            return;
        }

        $owner = self::resolveOwnerOrFail('save signal daily metrics');

        self::findPropertyOrFail((string) $metric->tracked_property_id, $owner);
    }

    public static function assertInteractionRuleWrite(?string $trackedPropertyId): void
    {
        if (! self::ownerScopingEnabled()) {
            return;
        }

        $owner = self::resolveOwnerOrFail('save signal interaction rules');

        if ($trackedPropertyId !== null && $trackedPropertyId !== '') {
            self::findPropertyOrFail($trackedPropertyId, $owner);
        }
    }

    public static function assertAlertDeliveryWrite(string $signalAlertLogId): void
    {
        if (! self::ownerScopingEnabled()) {
            return;
        }

        $owner = self::resolveOwnerOrFail('save signal alert deliveries');

        self::findOwnedOrFail(SignalAlertLog::class, $signalAlertLogId, $owner);
    }

    public static function findPropertyOrFail(string $propertyId, ?Model $owner): TrackedProperty
    {
        /** @var TrackedProperty */
        return self::findOwnedOrFail(TrackedProperty::class, $propertyId, $owner);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public static function findOwnedOrFail(string $modelClass, string $id, ?Model $owner): Model
    {
        /** @var TModel */
        return OwnerWriteGuard::findOrFailForOwner($modelClass, $id, $owner, includeGlobal: false);
    }

    private static function assertValidIngestionSource(SignalEvent $event): void
    {
        if (! in_array($event->ingestion_source, [SignalEvent::INGESTION_SOURCE_BROWSER, SignalEvent::INGESTION_SOURCE_TRUSTED], true)) {
            throw new AuthorizationException('Invalid ingestion_source: expected browser or trusted.');
        }
    }

    private static function assertTrackedPropertyImmutable(Model $model): void
    {
        if ($model->exists && $model->isDirty('tracked_property_id')) {
            throw new AuthorizationException('tracked_property_id is immutable once persisted.');
        }
    }

    private static function assertEventSamePropertyUnscoped(SignalEvent $event): void
    {
        $propertyId = (string) $event->tracked_property_id;

        self::findPropertyUnscopedOrFail($propertyId);

        if ($event->signal_session_id !== null && $event->signal_session_id !== '') {
            $session = SignalSession::query()->withoutOwnerScope()->whereKey((string) $event->signal_session_id)->first();

            if (! $session instanceof SignalSession) {
                throw new AuthorizationException('Invalid signal_session_id: referenced session does not exist.');
            }

            self::assertSameProperty((string) $session->tracked_property_id, $propertyId, 'signal_session_id');
        }

        if ($event->signal_identity_id !== null && $event->signal_identity_id !== '') {
            $identity = SignalIdentity::query()->withoutOwnerScope()->whereKey((string) $event->signal_identity_id)->first();

            if (! $identity instanceof SignalIdentity) {
                throw new AuthorizationException('Invalid signal_identity_id: referenced identity does not exist.');
            }

            self::assertSameProperty((string) $identity->tracked_property_id, $propertyId, 'signal_identity_id');
        }
    }

    private static function assertSessionSamePropertyUnscoped(SignalSession $session): void
    {
        $propertyId = (string) $session->tracked_property_id;

        self::findPropertyUnscopedOrFail($propertyId);

        if ($session->signal_identity_id !== null && $session->signal_identity_id !== '') {
            $identity = SignalIdentity::query()->withoutOwnerScope()->whereKey((string) $session->signal_identity_id)->first();

            if (! $identity instanceof SignalIdentity) {
                throw new AuthorizationException('Invalid signal_identity_id: referenced identity does not exist.');
            }

            self::assertSameProperty((string) $identity->tracked_property_id, $propertyId, 'signal_identity_id');
        }
    }

    private static function findPropertyUnscopedOrFail(string $propertyId): TrackedProperty
    {
        $property = $propertyId !== ''
            ? TrackedProperty::query()->withoutOwnerScope()->whereKey($propertyId)->first()
            : null;

        if (! $property instanceof TrackedProperty) {
            throw new AuthorizationException('Invalid tracked_property_id: referenced property does not exist.');
        }

        return $property;
    }

    private static function assertSameProperty(string $actualPropertyId, string $expectedPropertyId, string $attribute): void
    {
        if ($actualPropertyId !== $expectedPropertyId) {
            throw new AuthorizationException(sprintf(
                'Invalid %s: does not belong to the event tracked property.',
                $attribute,
            ));
        }
    }
}
