<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;
use Illuminate\Database\Eloquent\Model;

final class CheckoutSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordStarted(Model $session): ?SignalEvent
    {
        return $this->record(
            session: $session,
            eventName: (string) config('signals.integrations.checkout.started_event_name', 'checkout.started'),
            eventKey: 'checkout.started',
            occurredAttributes: ['created_at', 'updated_at'],
            properties: [
                'payment_gateway' => $this->support->stringValue($this->support->modelAttribute($session, 'selected_payment_gateway')),
                'shipping_method' => $this->support->stringValue($this->support->modelAttribute($session, 'selected_shipping_method')),
            ],
        );
    }

    public function recordCompleted(Model $session): ?SignalEvent
    {
        return $this->record(
            session: $session,
            eventName: (string) config('signals.integrations.checkout.event_name', 'checkout.completed'),
            eventKey: 'checkout.completed',
            occurredAttributes: ['completed_at', 'updated_at'],
            properties: [
                'payment_gateway' => $this->support->stringValue($this->support->modelAttribute($session, 'selected_payment_gateway')),
            ],
        );
    }

    /**
     * @param  list<string>  $occurredAttributes
     * @param  array<string, mixed>  $properties
     */
    private function record(Model $session, string $eventName, string $eventKey, array $occurredAttributes, array $properties): ?SignalEvent
    {
        if (! $this->support->isEventRecordingEnabled($eventKey)) {
            return null;
        }

        $trackedProperty = $this->support->resolveTrackedPropertyForModel($session);

        if ($trackedProperty === null) {
            return null;
        }

        $cartId = $this->support->stringValue($this->support->modelAttribute($session, 'cart_id'));
        $anonymousId = $this->growthVisitorId($session) ?? $cartId;
        $properties = array_merge([
            'checkout_session_id' => $this->support->stringValue($session->getKey()),
            'cart_id' => $cartId,
            'order_id' => $this->support->stringValue($this->support->modelAttribute($session, 'order_id')),
            'growth_visitor_id' => $anonymousId,
        ], $properties);

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => (string) config('signals.integrations.checkout.event_category', 'checkout'),
            'external_id' => $this->support->stringValue($this->support->modelAttribute($session, 'customer_id')),
            'anonymous_id' => $anonymousId,
            'occurred_at' => $this->support->requiredModelTimestamp($session, $occurredAttributes),
            'revenue_minor' => $this->support->requiredModelInt($session, 'grand_total'),
            'currency' => $this->support->stringValue($this->support->modelAttribute($session, 'currency'))
                ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => $this->support->enrichProperties($session, $trackedProperty, $properties),
        ]);
    }

    private function growthVisitorId(Model $session): ?string
    {
        $paymentData = $this->support->modelAttribute($session, 'payment_data');

        if (is_array($paymentData)) {
            $value = $this->support->stringValue(data_get($paymentData, 'growth_visitor_id'));

            if ($value !== null) {
                return $value;
            }
        }

        $billingData = $this->support->modelAttribute($session, 'billing_data');

        return is_array($billingData)
            ? $this->support->stringValue(data_get($billingData, 'metadata.growth_visitor_id'))
            : null;
    }
}
