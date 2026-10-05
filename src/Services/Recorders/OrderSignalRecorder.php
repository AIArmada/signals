<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class OrderSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordPaid(Model $order, string $transactionId, string $gateway, int $amount): ?SignalEvent
    {
        if ($transactionId === '' || $gateway === '') {
            throw new InvalidArgumentException('Trusted order paid signal requires a stable transaction id and gateway.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Trusted order paid signal requires a positive paid amount.');
        }

        if (! $this->support->isEventRecordingEnabled('order.paid')) {
            return null;
        }

        $orderKey = (string) $order->getKey();

        return $this->record(
            order: $order,
            eventName: (string) config('signals.integrations.orders.event_name', 'order.paid'),
            eventKey: 'order.paid',
            occurredAttributes: ['paid_at', 'updated_at'],
            idempotencyKey: $this->paidIdempotencyKey($orderKey, $gateway, $transactionId),
            sourceEventId: $transactionId,
            revenueMinor: $amount,
            extraProperties: [
                'gateway' => $gateway,
                'transaction_id' => $transactionId,
                'paid_amount_minor' => $amount,
            ],
        );
    }

    public function recordRefunded(Model $order, string $refundId, int $amount, ?string $reason = null): ?SignalEvent
    {
        if ($refundId === '') {
            throw new InvalidArgumentException('Trusted order refunded signal requires a stable refund id.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Trusted order refunded signal requires a positive refund amount.');
        }

        if (! $this->support->isEventRecordingEnabled('order.refunded')) {
            return null;
        }

        $orderKey = (string) $order->getKey();

        return $this->record(
            order: $order,
            eventName: (string) config('signals.integrations.orders.refund_event_name', 'order.refunded'),
            eventKey: 'order.refunded',
            occurredAttributes: ['updated_at'],
            idempotencyKey: $this->refundedIdempotencyKey($orderKey, $refundId),
            sourceEventId: $refundId,
            revenueMinor: 0,
            extraProperties: [
                'refund_id' => $refundId,
                'refund_amount_minor' => $amount,
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
        string $eventKey,
        array $occurredAttributes,
        string $idempotencyKey,
        ?string $sourceEventId,
        int $revenueMinor,
        array $extraProperties,
    ): ?SignalEvent {
        if (! $this->support->isEventRecordingEnabled($eventKey)) {
            return null;
        }

        $trackedProperty = $this->support->resolveTrackedPropertyForModel($order);

        if ($trackedProperty === null) {
            return null;
        }

        $extraProperties['order_total_minor'] = $this->support->requiredModelInt($order, 'grand_total');
        $occurredAt = $this->support->requiredModelTimestamp($order, $occurredAttributes);
        $cartId = $this->cartId($order);
        $anonymousId = $this->growthVisitorId($order) ?? $cartId;

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => $eventKey === 'order.refunded'
                ? (string) config('signals.integrations.orders.refund_event_category', 'conversion')
                : (string) config('signals.integrations.orders.event_category', 'conversion'),
            'external_id' => $this->support->stringValue($this->support->modelAttribute($order, 'customer_id')),
            'anonymous_id' => $anonymousId,
            'occurred_at' => $occurredAt,
            'idempotency_key' => $idempotencyKey,
            'source_event_id' => $sourceEventId,
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

    private function paidIdempotencyKey(string $orderKey, string $gateway, string $transactionId): string
    {
        $payload = json_encode(
            ['order' => $orderKey, 'gateway' => $gateway, 'transaction' => $transactionId],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return 'order-paid:' . hash('sha256', (string) $payload);
    }

    private function refundedIdempotencyKey(string $orderKey, string $refundId): string
    {
        $payload = json_encode(
            ['order' => $orderKey, 'refund' => $refundId],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return 'order-refunded:' . hash('sha256', (string) $payload);
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
