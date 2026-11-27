<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Ingestion\EntryPoints\Jobs\ReverseGeocodeSessionJob;
use AIArmada\Signals\Features\Ingestion\Models\SignalSession;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use Carbon\CarbonImmutable;

final readonly class CaptureSignalGeolocation
{
    /**
     * Persist browser-captured geolocation coordinates onto a session.
     *
     * Returns 202 Accepted unconditionally to prevent session enumeration
     * via timing or error differences.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, TrackedProperty $trackedProperty): void
    {
        if (! (bool) config('signals.features.geolocation.enabled', true)) {
            return;
        }

        $sessionIdentifier = $payload['session_identifier'] ?? null;
        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;

        if (
            ! is_string($sessionIdentifier)
            || $sessionIdentifier === ''
            || ! is_numeric($latitude)
            || ! is_numeric($longitude)
            || ! is_finite((float) $latitude)
            || ! is_finite((float) $longitude)
            || abs((float) $latitude) > 90
            || abs((float) $longitude) > 180
        ) {
            return;
        }

        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        if (($trackedProperty->owner_type !== null || $trackedProperty->owner_id !== null) && $owner === null) {
            return;
        }

        OwnerContext::withOwner($owner, function () use ($sessionIdentifier, $trackedProperty, $payload, $latitude, $longitude): void {
            $session = (new SignalSession)->getConnection()->transaction(function () use ($sessionIdentifier, $trackedProperty, $payload, $latitude, $longitude): ?SignalSession {
                $session = SignalSession::query()
                    ->where('session_identifier', $sessionIdentifier)
                    ->where('tracked_property_id', $trackedProperty->id)
                    ->lockForUpdate()
                    ->first();

                if ($session === null || ($session->latitude !== null && $session->longitude !== null)) {
                    return null;
                }

                $accuracy = $payload['accuracy'] ?? null;
                $accuracy = is_numeric($accuracy) && is_finite((float) $accuracy) && (float) $accuracy >= 0 && (float) $accuracy <= 2147483647
                    ? (int) round((float) $accuracy)
                    : null;

                $session->update([
                    'latitude' => (float) $latitude,
                    'longitude' => (float) $longitude,
                    'accuracy_meters' => $accuracy,
                    'geolocation_source' => 'browser',
                    'geolocation_captured_at' => CarbonImmutable::now(),
                ]);

                return $session;
            });

            if ($session === null) {
                return;
            }

            if (! (bool) config('signals.features.geolocation.reverse_geocode.enabled', false)) {
                return;
            }

            $ownerIsGlobal = $trackedProperty->owner_type === null && $trackedProperty->owner_id === null;
            $job = new ReverseGeocodeSessionJob(
                sessionId: $session->id,
                ownerType: $trackedProperty->owner_type,
                ownerId: $trackedProperty->owner_id,
                ownerIsGlobal: $ownerIsGlobal,
            );

            if ((bool) config('signals.features.geolocation.reverse_geocode.async', true)) {
                dispatch($job)->afterCommit();

                return;
            }

            $job->handle();
        });
    }
}
