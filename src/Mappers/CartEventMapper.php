<?php

declare(strict_types=1);

namespace AIArmada\Signals\Mappers;

use AIArmada\Cart\Events\CartCleared;
use AIArmada\Cart\Events\ItemAdded;
use AIArmada\Cart\Events\ItemRemoved;
use AIArmada\Signals\Contracts\MapCommerceEventToSignalInterface;

final class CartEventMapper implements MapCommerceEventToSignalInterface
{
    public function map(object $event): ?array
    {
        $cart = $event->cart ?? null;

        if (! is_object($cart)) {
            return null;
        }

        $cartId = method_exists($cart, 'getKey') ? $cart->getKey() : (property_exists($cart, 'id') ? $cart->id : null);

        $purchasableId = property_exists($event, 'purchasableId') ? $event->purchasableId : (property_exists($event, 'purchasable_id') ? $event->purchasable_id : null);

        return match ($event::class) {
            ItemAdded::class => [
                'event_type' => 'cart_item_added',
                'data' => [
                    'cart_id' => $cartId,
                    'purchasable_id' => $purchasableId,
                ],
            ],
            ItemRemoved::class => [
                'event_type' => 'cart_item_removed',
                'data' => [
                    'cart_id' => $cartId,
                    'purchasable_id' => $purchasableId,
                ],
            ],
            CartCleared::class => [
                'event_type' => 'cart_cleared',
                'data' => [
                    'cart_id' => $cartId,
                ],
            ],
            default => null,
        };
    }

    public function handles(): string
    {
        return ItemAdded::class;
    }

    /**
     * @return array<class-string>
     */
    public static function handledEvents(): array
    {
        return [
            ItemAdded::class,
            ItemRemoved::class,
            CartCleared::class,
        ];
    }
}
