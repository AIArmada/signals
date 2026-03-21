<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\SignalsIngestionRequestValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final class CaptureSignalPageView
{
    use AsAction;

    public function __construct(private readonly SignalsIngestionRequestValidator $requestValidator) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, array $payload): SignalEvent
    {
        $properties = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];

        if (($payload['title'] ?? null) !== null) {
            $properties['title'] = $payload['title'];
        }

        return app(IngestSignalEvent::class)->handle($trackedProperty, [
            'event_name' => (string) config('signals.defaults.page_view_event_name', 'page_view'),
            'event_category' => 'page_view',
            'external_id' => $payload['external_id'] ?? null,
            'anonymous_id' => $payload['anonymous_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'traits' => $payload['traits'] ?? null,
            'session_identifier' => $payload['session_identifier'] ?? null,
            'session_started_at' => $payload['session_started_at'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? null,
            'path' => $payload['path'] ?? null,
            'url' => $payload['url'] ?? null,
            'referrer' => $payload['referrer'] ?? null,
            'country' => $payload['country'] ?? null,
            'device_type' => $payload['device_type'] ?? null,
            'browser' => $payload['browser'] ?? null,
            'os' => $payload['os'] ?? null,
            'utm_source' => $payload['utm_source'] ?? null,
            'utm_medium' => $payload['utm_medium'] ?? null,
            'utm_campaign' => $payload['utm_campaign'] ?? null,
            'utm_content' => $payload['utm_content'] ?? null,
            'utm_term' => $payload['utm_term'] ?? null,
            'source' => $payload['source'] ?? null,
            'medium' => $payload['medium'] ?? null,
            'campaign' => $payload['campaign'] ?? null,
            'content' => $payload['content'] ?? null,
            'term' => $payload['term'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }

    public function asController(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'anonymous_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'traits' => ['nullable', 'array'],
            'session_identifier' => ['required', 'string', 'max:255'],
            'session_started_at' => ['nullable', 'date'],
            'occurred_at' => ['nullable', 'date'],
            'path' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'max:2'],
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
            'currency' => ['nullable', 'string', 'size:3'],
            'properties' => ['nullable', 'array'],
        ]);

        $trackedProperty = $this->requestValidator->resolveTrackedProperty($request, (string) $payload['write_key']);
        $event = $this->handle($trackedProperty, $payload);

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
