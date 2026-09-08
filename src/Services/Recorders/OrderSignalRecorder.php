<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;
use Illuminate\Database\Eloquent\Model;

final class OrderSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordPaid(Model $order, ?string $transactionId = null, ?string $gateway = null): ?SignalEvent
    {
        return $this->record(
            order: $order,
            eventName: (string) config('signals.integrations.orders.event_name', 'order.paid'),
            occurredAttributes: ['paid_at', 'updated_at'],
            revenueMinor: $this->support->requiredModelInt($order, 'grand_total'),
            extraProperties: [
                'gateway' => $gateway,
                'transaction_id' => $transactionId,
            ],
        );
    }

    public function recordRefunded(Model $order, int $amount, ?string $reason = null): ?SignalEvent
    {
        return $this->record(
            order: $order,
            eventName: (string) config('signals.integrations.orders.refund_event_name', 'order.refunded'),
            occurredAttributes: ['updated_at'],
            revenueMinor: $amount,
            extraProperties: [
                'refund_reason' => $reason,
            ],
        );
    }

    /**
     * @param  list<string>  $occurredAttributes
     * @param  array<string, mixed>  $extraProperties
     */
    private function record(
        Model $order,
        string $eventName,
        array $occurredAttributes,
        int $revenueMinor,
        array $extraProperties,
    ): ?SignalEvent {
        $eventKey = $eventName === 'order.refunded' ? 'order.refunded' : 'order.paid';

        if (! $this->support->isEventRecordingEnabled($eventKey)) {
            return null;
        }

        $trackedProperty = $this->support->resolveTrackedPropertyForModel($order);

        if ($trackedProperty === null) {
            return null;
        }

        $cartId = $this->cartId($order);
        $anonymousId = $this->growthVisitorId($order) ?? $cartId;

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => $eventKey === 'order.refunded'
                ? (string) config('signals.integrations.orders.refund_event_category', 'conversion')
                : (string) config('signals.integrations.orders.event_category', 'conversion'),
            'external_id' => $this->support->stringValue($this->support->modelAttribute($order, 'customer_id')),
            'anonymous_id' => $anonymousId,
            'occurred_at' => $this->support->requiredModelTimestamp($order, $occurredAttributes),
            'revenue_minor' => $revenueMinor,
            'currency' => $this->support->stringValue($this->support->modelAttribute($order, 'currency'))
                ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => $this->support->enrichProperties($order, $trackedProperty, array_merge([
                'checkout_session_id' => $this->metadataValue($order, 'checkout_session_id'),
                'cart_id' => $cartId,
                'order_id' => $this->support->stringValue($order->getKey()),
                'order_number' => $this->support->stringValue($this->support->modelAttribute($order, 'order_number')),
                'growth_visitor_id' => $anonymousId,
            ], $extraProperties)),
        ]);
    }

    private function cartId(Model $order): ?string
    {
        return $this->support->stringValue($this->support->modelAttribute($order, 'cart_id'))
            ?? $this->metadataValue($order, 'cart_id');
    }

    private function growthVisitorId(Model $order): ?string
    {
        return $this->metadataValue($order, 'payment_data.growth_visitor_id')
            ?? $this->metadataValue($order, 'growth_visitor_id');
    }

    private function metadataValue(Model $order, string $key): ?string
    {
        $metadata = $this->support->modelAttribute($order, 'metadata');

        return is_array($metadata) ? $this->support->stringValue(data_get($metadata, $key)) : null;
    }
}
