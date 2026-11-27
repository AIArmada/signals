<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Signals\Features\Ingestion\Api\IngestedSignalEvent;
use AIArmada\Signals\Features\Ingestion\Api\SignalIngestion;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use AIArmada\Signals\Features\Ingestion\Support\BrowserPayloadPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class IngestSignal implements SignalIngestion
{
    public function __construct(
        private IngestSignalEvent $ingestSignalEvent,
        private BrowserPayloadPolicy $payloadPolicy,
    ) {}

    public function handle(string $trackedPropertyId, array $payload, bool $trusted): IngestedSignalEvent
    {
        $trackedProperty = $this->resolveTrackedProperty($trackedPropertyId);

        if (! $trackedProperty->is_active) {
            throw new InvalidArgumentException('The tracked property is inactive.');
        }

        $eventName = $payload['event_name'] ?? null;

        if (! is_string($eventName) || mb_trim($eventName) === '' || mb_strlen($eventName) > 255) {
            throw new InvalidArgumentException('The event name must be a non-empty string of at most 255 characters.');
        }

        if (! $trusted) {
            $this->payloadPolicy->assertStructure($payload, 'browser');
            $this->payloadPolicy->assertEvent($eventName, $payload);
        }

        $event = $this->ingestSignalEvent->handle($trackedProperty, $payload, $trusted);

        return new IngestedSignalEvent(
            id: (string) $event->getKey(),
            trackedPropertyId: (string) $event->tracked_property_id,
            eventName: (string) $event->event_name,
            eventCategory: (string) $event->event_category,
            occurredAt: CarbonImmutable::parse($event->occurred_at),
            idempotencyKey: $event->idempotency_key,
            sourceEventId: $event->source_event_id,
            properties: is_array($event->properties) ? $event->properties : [],
            revenueMinor: (int) $event->revenue_minor,
            currency: (string) $event->currency,
        );
    }

    private function resolveTrackedProperty(string $trackedPropertyId): TrackedProperty
    {
        if (! Str::isUuid($trackedPropertyId)) {
            throw new InvalidArgumentException(sprintf('Invalid tracked property id [%s].', $trackedPropertyId));
        }

        if (! TrackedProperty::ownerScopeConfig()->enabled) {
            $trackedProperty = TrackedProperty::query()->whereKey($trackedPropertyId)->first();

            if (! $trackedProperty instanceof TrackedProperty) {
                throw new InvalidArgumentException(sprintf('Unknown tracked property [%s].', $trackedPropertyId));
            }

            return $trackedProperty;
        }

        $trackedProperty = OwnerWriteGuard::findOrFailForOwner(
            modelClass: TrackedProperty::class,
            id: $trackedPropertyId,
            includeGlobal: false,
            message: 'The tracked property is not accessible in the current owner scope.',
        );

        if (! $trackedProperty instanceof TrackedProperty) {
            throw new InvalidArgumentException(sprintf('Unknown tracked property [%s].', $trackedPropertyId));
        }

        return $trackedProperty;
    }
}
