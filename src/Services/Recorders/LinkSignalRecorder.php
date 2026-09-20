<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

final class LinkSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordClicked(Model $link, Model $click): ?SignalEvent
    {
        $trackedProperty = $this->support->resolveTrackedPropertyForOwnerReference(
            $this->support->stringValue($link->getAttribute('owner_type')),
            $link->getAttribute('owner_id'),
            null,
            'links',
        );

        if ($trackedProperty === null) {
            return null;
        }

        $clickId = (string) $click->getKey();
        $slug = $this->support->stringValue($link->getAttribute('slug'));
        $path = $slug !== null && $slug !== ''
            ? '/' . mb_trim((string) config('links.routing.prefix', 'go'), '/') . '/' . $slug
            : null;

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.links.clicked_event_name', 'link.clicked'),
            'event_category' => (string) config('signals.integrations.links.event_category', 'engagement'),
            'occurred_at' => $this->support->timestampValue($click->getAttribute('occurred_at')) ?? CarbonImmutable::now()->toAtomString(),
            'idempotency_key' => 'link-click:' . $clickId,
            'source_event_id' => $clickId,
            'path' => $path,
            'url' => $path !== null ? url($path) : null,
            'referrer' => $this->support->stringValue($click->getAttribute('referrer')),
            'utm_source' => $this->support->stringValue($click->getAttribute('utm_source')),
            'utm_medium' => $this->support->stringValue($click->getAttribute('utm_medium')),
            'utm_campaign' => $this->support->stringValue($click->getAttribute('utm_campaign')),
            'utm_content' => $this->support->stringValue($click->getAttribute('utm_content')),
            'utm_term' => $this->support->stringValue($click->getAttribute('utm_term')),
            'revenue_minor' => 0,
            'currency' => $trackedProperty->currency,
            'properties' => array_filter([
                'link_id' => (string) $link->getKey(),
                'link_slug' => $slug,
                'link_name' => $this->support->stringValue($link->getAttribute('name')),
                'destination_host' => $this->destinationHost($link),
                'is_bot' => (bool) $click->getAttribute('is_bot'),
                'device_type' => $this->support->stringValue($click->getAttribute('device_type')),
                'browser' => $this->support->stringValue($click->getAttribute('browser')),
                'os' => $this->support->stringValue($click->getAttribute('os')),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
    }

    private function destinationHost(Model $link): ?string
    {
        $destination = $this->support->stringValue($link->getAttribute('destination_url'));

        if ($destination === null) {
            return null;
        }

        $host = parse_url($destination, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
