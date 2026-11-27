<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\EntryPoints\Http;

use AIArmada\Signals\Features\Ingestion\Actions\IngestSignalEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class BrowserEventController
{
    public function __construct(
        private IngestSignalEvent $action,
        private SignalsIngestionRequestValidator $requestValidator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string'],
            'event_name' => ['required', 'string', 'max:255'],
            'event_category' => ['nullable', 'string', 'max:100'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'anonymous_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'traits' => ['nullable', 'array'],
            'session_identifier' => ['nullable', 'string', 'max:255'],
            'session_started_at' => ['nullable', 'date'],
            'occurred_at' => ['nullable', 'date'],
            'path' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string'],
            'referrer' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'max:2'],
            'device_type' => ['nullable', 'string', 'max:50'],
            'browser' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:100'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'medium' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:255'],
            'term' => ['nullable', 'string', 'max:255'],
            'browser_version' => ['nullable', 'string', 'max:50'],
            'os_version' => ['nullable', 'string', 'max:50'],
            'device_brand' => ['nullable', 'string', 'max:100'],
            'device_model' => ['nullable', 'string', 'max:100'],
            'is_bot' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'properties' => ['nullable', 'array'],
        ]);

        $this->requestValidator->assertBrowserPayload(
            request: $request,
            writeKey: (string) $payload['write_key'],
            eventName: (string) $payload['event_name'],
        );
        $trackedProperty = $this->requestValidator->resolveTrackedProperty($request, (string) $payload['write_key']);
        $event = $this->action->handle($trackedProperty, $payload, trusted: false);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'event_id' => $event->id,
                'tracked_property_id' => $trackedProperty->id,
                'identity_id' => $event->signal_identity_id,
                'session_id' => $event->signal_session_id,
            ],
        ], 202);
    }
}
