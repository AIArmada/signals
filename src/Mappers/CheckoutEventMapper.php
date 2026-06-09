<?php

declare(strict_types=1);

namespace AIArmada\Signals\Mappers;

use AIArmada\Checkout\Events\CheckoutCompleted;
use AIArmada\Checkout\Events\CheckoutStarted;
use AIArmada\Signals\Contracts\MapCommerceEventToSignalInterface;

final class CheckoutEventMapper implements MapCommerceEventToSignalInterface
{
    public function map(object $event): ?array
    {
        $cart = $event->cart ?? $event->checkout ?? null;

        if (! is_object($cart)) {
            return null;
        }

        $cartId = method_exists($cart, 'getKey') ? $cart->getKey() : (property_exists($cart, 'id') ? $cart->id : null);

        return match ($event::class) {
            CheckoutStarted::class => [
                'event_type' => 'checkout_started',
                'data' => [
                    'cart_id' => $cartId,
                ],
            ],
            CheckoutCompleted::class => [
                'event_type' => 'checkout_completed',
                'data' => [
                    'cart_id' => $cartId,
                    'order_id' => method_exists($cart, 'order') && $cart->order ? $cart->order->getKey() : null,
                ],
            ],
            default => null,
        };
    }

    public function handles(): string
    {
        return CheckoutStarted::class;
    }

    /**
     * @return array<class-string>
     */
    public static function handledEvents(): array
    {
        return [
            CheckoutStarted::class,
            CheckoutCompleted::class,
        ];
    }
}
