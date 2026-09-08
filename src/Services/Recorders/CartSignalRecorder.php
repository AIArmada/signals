<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;

final class CartSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordItemAdded(object $cart, object $item): ?SignalEvent
    {
        return $this->recordItem(
            cart: $cart,
            item: $item,
            eventName: (string) config('signals.integrations.cart.item_added_event_name', 'cart.item.added'),
        );
    }

    public function recordItemRemoved(object $cart, object $item): ?SignalEvent
    {
        return $this->recordItem(
            cart: $cart,
            item: $item,
            eventName: (string) config('signals.integrations.cart.item_removed_event_name', 'cart.item.removed'),
        );
    }

    public function recordCleared(object $cart): ?SignalEvent
    {
        return $this->recordCart($cart, (string) config('signals.integrations.cart.cleared_event_name', 'cart.cleared'));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemProperties(object $item): array
    {
        $price = $this->support->requiredPublicInt($item, 'price');
        $quantity = $this->support->requiredPublicInt($item, 'quantity');

        return [
            'item_id' => $this->support->requiredPublicScalar($item, 'id'),
            'item_name' => $this->support->requiredPublicScalar($item, 'name'),
            'quantity' => $quantity,
            'unit_price_minor' => $price,
            'line_total_minor' => $price * $quantity,
        ];
    }

    private function recordItem(object $cart, object $item, string $eventName): ?SignalEvent
    {
        return $this->recordCart($cart, $eventName, $this->itemProperties($item));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordCart(object $cart, string $eventName, array $properties = []): ?SignalEvent
    {
        $trackedProperty = $this->support->resolveTrackedPropertyForCart($cart);

        if ($trackedProperty === null) {
            return null;
        }

        $cartIdentifier = $this->support->requiredStringMethod($cart, 'getIdentifier');
        $instanceName = $this->support->requiredStringMethod($cart, 'instance');
        $occurredAt = $this->support->timestampValue(
            $this->support->requiredMethod($cart, 'getUpdatedAt')
                ?? $this->support->requiredMethod($cart, 'getCreatedAt'),
        );

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => (string) config('signals.integrations.cart.event_category', 'cart'),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->support->buildCartSessionIdentifier($cartIdentifier, $instanceName),
            'occurred_at' => $occurredAt,
            'revenue_minor' => 0,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter(array_merge([
                'cart_id' => $this->support->stringValue($this->support->requiredMethod($cart, 'getId')),
                'cart_identifier' => $cartIdentifier,
                'cart_instance' => $instanceName,
                'cart_total_minor' => $this->support->requiredIntMethod($cart, 'getRawTotal'),
                'total_quantity' => $this->support->requiredIntMethod($cart, 'getTotalQuantity'),
                'unique_item_count' => $this->support->requiredIntMethod($cart, 'countItems'),
            ], $properties), static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
