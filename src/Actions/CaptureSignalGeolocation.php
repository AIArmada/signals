<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Jobs\ReverseGeocodeSessionJob;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\SignalsIngestionRequestValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final class CaptureSignalGeolocation
{
    use AsAction;

    public function __construct(private readonly SignalsIngestionRequestValidator $requestValidator) {}

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
        ) {
            return;
        }

        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        OwnerContext::withOwner($owner, function () use ($sessionIdentifier, $trackedProperty, $payload, $latitude, $longitude): void {
            $session = SignalSession::query()
                ->where('session_identifier', $sessionIdentifier)
                ->where('tracked_property_id', $trackedProperty->id)
                ->first();

            if ($session === null) {
                return;
            }

            if ($session->latitude !== null && $session->longitude !== null) {
                return;
            }

            $accuracy = is_numeric($payload['accuracy'] ?? null)
                ? (int) round((float) $payload['accuracy'])
                : null;

            $session->update([
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'accuracy_meters' => $accuracy,
                'geolocation_source' => 'browser',
                'geolocation_captured_at' => CarbonImmutable::now(),
            ]);

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

    public function asController(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string'],
            'session_identifier' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Validate write key resolves to a tracked property (raises 403 on failure)
        $trackedProperty = $this->requestValidator->resolveTrackedProperty($request, (string) $payload['write_key']);

        $this->handle($payload, $trackedProperty);

        return response()->json(['status' => 'ok'], 202);
    }
}
