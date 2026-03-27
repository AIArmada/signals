<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\Signals\Jobs\ReverseGeocodeSessionJob;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Services\SignalLocationResolverPipeline;
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
    public function handle(array $payload, ?string $trackedPropertyId = null): void
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

        $query = SignalSession::where('session_identifier', $sessionIdentifier);

        if ($trackedPropertyId !== null) {
            $query->where('tracked_property_id', $trackedPropertyId);
        }

        $session = $query->first();

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

        if ((bool) config('signals.features.geolocation.reverse_geocode.enabled', false)) {
            $async = (bool) config('signals.features.geolocation.reverse_geocode.async', true);

            if ($async) {
                dispatch(new ReverseGeocodeSessionJob($session->id))->afterCommit();
            } else {
                app(ReverseGeocodeSessionJob::class, ['sessionId' => $session->id])
                    ->handle(app(SignalLocationResolverPipeline::class));
            }
        }
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

        $this->handle($payload, $trackedProperty->id);

        return response()->json(['status' => 'ok'], 202);
    }
}
