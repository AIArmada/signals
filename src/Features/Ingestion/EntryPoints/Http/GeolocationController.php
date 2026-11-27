<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\EntryPoints\Http;

use AIArmada\Signals\Features\Ingestion\Actions\CaptureSignalGeolocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class GeolocationController
{
    public function __construct(
        private CaptureSignalGeolocation $action,
        private SignalsIngestionRequestValidator $requestValidator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string'],
            'session_identifier' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:2147483647'],
        ]);

        // Validate write key resolves to a tracked property (raises 403 on failure)
        $this->requestValidator->assertPublicPayload(
            request: $request,
            writeKey: (string) $payload['write_key'],
        );
        $trackedProperty = $this->requestValidator->resolveTrackedProperty($request, (string) $payload['write_key']);

        $this->action->handle($payload, $trackedProperty);

        return response()->json(['status' => 'ok'], 202);
    }
}
