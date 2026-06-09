<?php

declare(strict_types=1);

namespace AIArmada\Signals\Mappers;

use AIArmada\Orders\Events\OrderCanceled;
use AIArmada\Orders\Events\OrderCompleted;
use AIArmada\Orders\Events\OrderPaid;
use AIArmada\Orders\Events\OrderRefunded;
use AIArmada\Signals\Contracts\MapCommerceEventToSignalInterface;
use Illuminate\Database\Eloquent\Model;

final class OrderEventMapper implements MapCommerceEventToSignalInterface
{
    public function map(object $event): ?array
    {
        $order = $event->order ?? null;

        if (! $order instanceof Model) {
            return null;
        }

        return match ($event::class) {
            OrderPaid::class => [
                'event_type' => 'order_paid',
                'data' => [
                    'order_id' => $order->getKey(),
                    'total' => $order->grand_total ?? 0,
                    'currency' => $order->currency ?? 'MYR',
                    'transaction_id' => $event->transactionId ?? null,
                ],
            ],
            OrderCompleted::class => [
                'event_type' => 'order_completed',
                'data' => [
                    'order_id' => $order->getKey(),
                    'total' => $order->grand_total ?? 0,
                ],
            ],
            OrderCanceled::class => [
                'event_type' => 'order_canceled',
                'data' => [
                    'order_id' => $order->getKey(),
                    'reason' => $event->reason ?? null,
                ],
            ],
            OrderRefunded::class => [
                'event_type' => 'order_refunded',
                'data' => [
                    'order_id' => $order->getKey(),
                    'amount' => $event->amount ?? 0,
                ],
            ],
            default => null,
        };
    }

    public function handles(): string
    {
        return OrderPaid::class;
    }

    /**
     * @return array<class-string>
     */
    public static function handledEvents(): array
    {
        return [
            OrderPaid::class,
            OrderCompleted::class,
            OrderCanceled::class,
            OrderRefunded::class,
        ];
    }
}
