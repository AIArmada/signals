<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;

final readonly class CaptureSignalPageView
{
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
            'country_code' => $payload['country_code'] ?? null,
            'device_type' => $payload['device_type'] ?? null,
            'device_brand' => $payload['device_brand'] ?? null,
            'device_model' => $payload['device_model'] ?? null,
            'browser' => $payload['browser'] ?? null,
            'browser_version' => $payload['browser_version'] ?? null,
            'os' => $payload['os'] ?? null,
            'os_version' => $payload['os_version'] ?? null,
            'is_bot' => $payload['is_bot'] ?? null,
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
            'properties' => $properties === [] ? null : $properties,
        ], trusted: false);
    }
}
