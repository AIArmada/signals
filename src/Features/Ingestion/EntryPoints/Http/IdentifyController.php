<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\EntryPoints\Http;

use AIArmada\Signals\Features\Ingestion\Actions\IdentifySignalIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class IdentifyController
{
    public function __construct(
        private IdentifySignalIdentity $action,
        private SignalsIngestionRequestValidator $requestValidator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'anonymous_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'traits' => ['nullable', 'array'],
            'seen_at' => ['nullable', 'date'],
        ]);

        if (($payload['external_id'] ?? null) === null && ($payload['anonymous_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'external_id' => 'Either external_id or anonymous_id is required.',
            ]);
        }

        $this->requestValidator->assertPublicPayload(
            request: $request,
            writeKey: (string) $payload['write_key'],
        );
        $trackedProperty = $this->requestValidator->resolveTrackedProperty($request, (string) $payload['write_key']);
        $identity = $this->action->handle($trackedProperty, $payload);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'identity_id' => $identity->id,
                'tracked_property_id' => $trackedProperty->id,
            ],
        ], 202);
    }
}
