<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;

final class FilamentCartSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function record(object $event, string $eventName): ?SignalEvent
    {
        $ownerType = $this->support->optionalPublicScalar($event, 'ownerType');
        $ownerId = $this->support->optionalPublicScalar($event, 'ownerId');
        $trackedProperty = $this->support->resolveTrackedPropertyForOwnerReference($ownerType, $ownerId, null, 'filament_cart');

        if ($trackedProperty === null) {
            return null;
        }

        $cartIdentifier = $this->support->requiredPublicScalar($event, 'cartIdentifier');
        $cartInstance = $this->support->requiredPublicScalar($event, 'cartInstance');
        $sourceEventId = $this->support->requiredPublicScalar($event, 'sourceEventId');
        $totalMinor = $this->support->requiredPublicInt($event, 'totalMinor');
        $currency = $this->support->requiredPublicScalar($event, 'currency');
        $occurredAt = $this->support->requiredPublicScalar($event, 'occurredAt');

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => (string) config('signals.integrations.filament_cart.event_category', 'cart'),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->support->buildCartSessionIdentifier($cartIdentifier, $cartInstance),
            'occurred_at' => $occurredAt,
            'revenue_minor' => $totalMinor,
            'currency' => $currency,
            'source_event_id' => $sourceEventId,
            'properties' => array_filter([
                'source_event_id' => $sourceEventId,
                'cart_id' => $this->support->optionalPublicScalar($event, 'cartId'),
                'cart_identifier' => $cartIdentifier,
                'cart_instance' => $cartInstance,
                'cart_total_minor' => $totalMinor,
                'subtotal_minor' => $this->support->requiredPublicInt($event, 'subtotalMinor'),
                'total_quantity' => $this->support->requiredPublicInt($event, 'totalQuantity'),
                'unique_item_count' => $this->support->requiredPublicInt($event, 'uniqueItemCount'),
                'item_count' => $this->support->requiredPublicInt($event, 'itemCount'),
                'currency' => $currency,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
