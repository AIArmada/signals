<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Actions\IngestSignalEvent;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final class CommerceSignalsRecorder
{
    public function __construct(
        private readonly TrackedPropertyResolver $trackedPropertyResolver,
        private readonly IngestSignalEvent $ingestSignalEvent,
    ) {}

    public function recordCheckoutCompleted(Model $session): ?SignalEvent
    {
        if (! $this->isEventRecordingEnabled('checkout.completed')) {
            return null;
        }

        $trackedProperty = $this->trackedPropertyResolver->resolveForModel($session);

        if ($trackedProperty === null) {
            return null;
        }

        $anonymousId = $this->growthVisitorIdFromCheckoutSession($session)
            ?? $this->stringValue($this->attributeValue($session, 'cart_id'));

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.checkout.event_name', 'checkout.completed'),
            'event_category' => (string) config('signals.integrations.checkout.event_category', 'checkout'),
            'external_id' => $this->stringValue($this->attributeValue($session, 'customer_id')),
            'anonymous_id' => $anonymousId,
            'occurred_at' => $this->timestampValue($this->attributeValue($session, 'completed_at') ?? $this->attributeValue($session, 'updated_at')),
            'revenue_minor' => (int) ($this->attributeValue($session, 'grand_total') ?? 0),
            'currency' => $this->stringValue($this->attributeValue($session, 'currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => $this->enrichProperties($session, $trackedProperty, [
                'checkout_session_id' => $this->stringValue($session->getKey()),
                'cart_id' => $this->stringValue($this->attributeValue($session, 'cart_id')),
                'order_id' => $this->stringValue($this->attributeValue($session, 'order_id')),
                'payment_gateway' => $this->stringValue($this->attributeValue($session, 'selected_payment_gateway')),
                'growth_visitor_id' => $anonymousId,
            ]),
        ]);
    }

    public function recordCheckoutStarted(Model $session): ?SignalEvent
    {
        if (! $this->isEventRecordingEnabled('checkout.started')) {
            return null;
        }

        $trackedProperty = $this->trackedPropertyResolver->resolveForModel($session);

        if ($trackedProperty === null) {
            return null;
        }

        $anonymousId = $this->growthVisitorIdFromCheckoutSession($session)
            ?? $this->stringValue($this->attributeValue($session, 'cart_id'));

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.checkout.started_event_name', 'checkout.started'),
            'event_category' => (string) config('signals.integrations.checkout.event_category', 'checkout'),
            'external_id' => $this->stringValue($this->attributeValue($session, 'customer_id')),
            'anonymous_id' => $anonymousId,
            'occurred_at' => $this->timestampValue($this->attributeValue($session, 'created_at') ?? $this->attributeValue($session, 'updated_at')),
            'revenue_minor' => (int) ($this->attributeValue($session, 'grand_total') ?? 0),
            'currency' => $this->stringValue($this->attributeValue($session, 'currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => $this->enrichProperties($session, $trackedProperty, [
                'checkout_session_id' => $this->stringValue($session->getKey()),
                'cart_id' => $this->stringValue($this->attributeValue($session, 'cart_id')),
                'payment_gateway' => $this->stringValue($this->attributeValue($session, 'selected_payment_gateway')),
                'shipping_method' => $this->stringValue($this->attributeValue($session, 'selected_shipping_method')),
                'growth_visitor_id' => $anonymousId,
            ]),
        ]);
    }

    public function recordOrderPaid(Model $order, ?string $transactionId = null, ?string $gateway = null): ?SignalEvent
    {
        if (! $this->isEventRecordingEnabled('order.paid')) {
            return null;
        }

        $trackedProperty = $this->trackedPropertyResolver->resolveForModel($order);

        if ($trackedProperty === null) {
            return null;
        }

        $checkoutSessionId = $this->checkoutSessionIdForOrder($order);
        $cartId = $this->cartIdForOrder($order);
        $anonymousId = $this->growthVisitorIdFromOrder($order) ?? $cartId;

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.orders.event_name', 'order.paid'),
            'event_category' => (string) config('signals.integrations.orders.event_category', 'conversion'),
            'external_id' => $this->stringValue($this->attributeValue($order, 'customer_id')),
            'anonymous_id' => $anonymousId,
            'occurred_at' => $this->timestampValue($this->attributeValue($order, 'paid_at') ?? $this->attributeValue($order, 'updated_at')),
            'revenue_minor' => (int) ($this->attributeValue($order, 'grand_total') ?? 0),
            'currency' => $this->stringValue($this->attributeValue($order, 'currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => $this->enrichProperties($order, $trackedProperty, [
                'checkout_session_id' => $checkoutSessionId,
                'cart_id' => $cartId,
                'order_id' => $this->stringValue($order->getKey()),
                'order_number' => $this->stringValue($this->attributeValue($order, 'order_number')),
                'gateway' => $gateway,
                'transaction_id' => $transactionId,
                'growth_visitor_id' => $anonymousId,
            ]),
        ]);
    }

    public function recordOrderRefunded(Model $order, int $amount, ?string $reason = null): ?SignalEvent
    {
        if (! $this->isEventRecordingEnabled('order.refunded')) {
            return null;
        }

        $trackedProperty = $this->trackedPropertyResolver->resolveForModel($order);

        if ($trackedProperty === null) {
            return null;
        }

        $checkoutSessionId = $this->checkoutSessionIdForOrder($order);
        $cartId = $this->cartIdForOrder($order);
        $anonymousId = $this->growthVisitorIdFromOrder($order) ?? $cartId;

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.orders.refund_event_name', 'order.refunded'),
            'event_category' => (string) config('signals.integrations.orders.refund_event_category', 'conversion'),
            'external_id' => $this->stringValue($this->attributeValue($order, 'customer_id')),
            'anonymous_id' => $anonymousId,
            'occurred_at' => $this->timestampValue($this->attributeValue($order, 'updated_at')),
            'revenue_minor' => $amount,
            'currency' => $this->stringValue($this->attributeValue($order, 'currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => $this->enrichProperties($order, $trackedProperty, [
                'checkout_session_id' => $checkoutSessionId,
                'cart_id' => $cartId,
                'order_id' => $this->stringValue($order->getKey()),
                'order_number' => $this->stringValue($this->attributeValue($order, 'order_number')),
                'refund_reason' => $reason,
                'growth_visitor_id' => $anonymousId,
            ]),
        ]);
    }

    public function recordCartItemAdded(object $cart, object $item): ?SignalEvent
    {
        return $this->recordCartEvent(
            cart: $cart,
            eventName: (string) config('signals.integrations.cart.item_added_event_name', 'cart.item.added'),
            properties: array_filter([
                'item_id' => $this->readPublicScalar($item, 'id'),
                'item_name' => $this->readPublicScalar($item, 'name'),
                'quantity' => $this->readPublicInt($item, 'quantity'),
                'unit_price_minor' => $this->readPublicInt($item, 'price'),
                'line_total_minor' => $this->calculateLineTotal($item),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function recordCartItemRemoved(object $cart, object $item): ?SignalEvent
    {
        return $this->recordCartEvent(
            cart: $cart,
            eventName: (string) config('signals.integrations.cart.item_removed_event_name', 'cart.item.removed'),
            properties: array_filter([
                'item_id' => $this->readPublicScalar($item, 'id'),
                'item_name' => $this->readPublicScalar($item, 'name'),
                'quantity' => $this->readPublicInt($item, 'quantity'),
                'unit_price_minor' => $this->readPublicInt($item, 'price'),
                'line_total_minor' => $this->calculateLineTotal($item),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function recordCartCleared(object $cart): ?SignalEvent
    {
        return $this->recordCartEvent(
            cart: $cart,
            eventName: (string) config('signals.integrations.cart.cleared_event_name', 'cart.cleared'),
        );
    }

    public function recordCartSnapshotSynced(object $event): ?SignalEvent
    {
        return $this->recordFilamentCartEvent(
            event: $event,
            eventName: (string) config('signals.integrations.filament_cart.snapshot_synced_event_name', 'cart.snapshot.synced'),
        );
    }

    public function recordCartCheckoutStarted(object $event): ?SignalEvent
    {
        return $this->recordFilamentCartEvent(
            event: $event,
            eventName: (string) config('signals.integrations.filament_cart.checkout_started_event_name', 'cart.checkout.started'),
        );
    }

    public function recordCartAbandoned(object $event): ?SignalEvent
    {
        return $this->recordFilamentCartEvent(
            event: $event,
            eventName: (string) config('signals.integrations.filament_cart.abandoned_event_name', 'cart.abandoned'),
        );
    }

    public function recordHighValueCartDetected(object $event): ?SignalEvent
    {
        return $this->recordFilamentCartEvent(
            event: $event,
            eventName: (string) config('signals.integrations.filament_cart.high_value_detected_event_name', 'cart.high_value.detected'),
        );
    }

    public function recordVoucherApplied(object $cart, object $voucher): ?SignalEvent
    {
        return $this->recordVoucherEvent(
            cart: $cart,
            voucher: $voucher,
            eventName: (string) config('signals.integrations.vouchers.applied_event_name', 'voucher.applied'),
        );
    }

    public function recordVoucherRemoved(object $cart, object $voucher): ?SignalEvent
    {
        return $this->recordVoucherEvent(
            cart: $cart,
            voucher: $voucher,
            eventName: (string) config('signals.integrations.vouchers.removed_event_name', 'voucher.removed'),
        );
    }

    public function recordAffiliateAttributed(object $attribution): ?SignalEvent
    {
        $attributionModel = $this->resolveAffiliateModel(
            'AIArmada\\Affiliates\\Models\\AffiliateAttribution',
            $this->readPublicScalar($attribution, 'id'),
            $this->readPublicScalar($attribution, 'affiliateId'),
            $this->readPublicScalar($attribution, 'affiliateCode'),
            $this->readPublicScalar($attribution, 'ownerType'),
            $this->readPublicScalar($attribution, 'ownerId'),
        );

        if (! $attributionModel instanceof Model) {
            return null;
        }

        $trackedProperty = $this->resolveTrackedPropertyForAffiliateModel($attributionModel);

        if ($trackedProperty === null) {
            return null;
        }

        $subjectIdentifier = $this->stringValue($attributionModel->getAttribute('subject_identifier'))
            ?? $this->readPublicScalar($attribution, 'subjectIdentifier');
        $subjectInstance = $this->stringValue($attributionModel->getAttribute('subject_instance'))
            ?? $this->readPublicScalar($attribution, 'subjectInstance');
        $cartIdentifier = $this->stringValue($attributionModel->getAttribute('cart_identifier'))
            ?? $this->readPublicScalar($attribution, 'cartIdentifier')
            ?? $subjectIdentifier
            ?? $this->stringValue($attributionModel->getAttribute('cookie_value'))
            ?? $this->readPublicScalar($attribution, 'cookieValue');
        $cartInstance = $this->stringValue($attributionModel->getAttribute('cart_instance'))
            ?? $this->readPublicScalar($attribution, 'cartInstance')
            ?? $subjectInstance
            ?? 'default';
        $landingUrl = $this->stringValue($attributionModel->getAttribute('landing_url'));
        $referrerUrl = $this->stringValue($attributionModel->getAttribute('referrer_url'));

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.attributed_event_name', 'affiliate.attributed'),
            'event_category' => (string) config('signals.integrations.affiliates.attributed_event_category', 'acquisition'),
            'external_id' => $this->stringValue($attributionModel->getAttribute('user_id')),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->buildAffiliateSessionIdentifier($cartIdentifier, $cartInstance),
            'occurred_at' => $this->timestampValue($attributionModel->getAttribute('last_seen_at') ?? $attributionModel->getAttribute('created_at')),
            'path' => $landingUrl,
            'url' => $landingUrl,
            'referrer' => $referrerUrl,
            'source' => $this->stringValue($attributionModel->getAttribute('source')),
            'medium' => $this->stringValue($attributionModel->getAttribute('medium')),
            'campaign' => $this->stringValue($attributionModel->getAttribute('campaign')),
            'content' => $this->stringValue($attributionModel->getAttribute('content')),
            'term' => $this->stringValue($attributionModel->getAttribute('term')),
            'revenue_minor' => 0,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'attribution_id' => $this->stringValue($attributionModel->getKey()),
                'affiliate_id' => $this->stringValue($attributionModel->getAttribute('affiliate_id'))
                    ?? $this->readPublicScalar($attribution, 'affiliateId'),
                'affiliate_code' => $this->stringValue($attributionModel->getAttribute('affiliate_code'))
                    ?? $this->readPublicScalar($attribution, 'affiliateCode'),
                'subject_identifier' => $subjectIdentifier,
                'subject_instance' => $subjectInstance,
                'cart_identifier' => $this->stringValue($attributionModel->getAttribute('cart_identifier')),
                'cart_instance' => $this->stringValue($attributionModel->getAttribute('cart_instance')),
                'cookie_value' => $this->stringValue($attributionModel->getAttribute('cookie_value')),
                'voucher_code' => $this->stringValue($attributionModel->getAttribute('voucher_code')),
                'landing_url' => $landingUrl,
                'referrer_url' => $referrerUrl,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordAffiliateConversionRecorded(object $conversion): ?SignalEvent
    {
        $conversionModel = $this->resolveAffiliateModel(
            'AIArmada\\Affiliates\\Models\\AffiliateConversion',
            $this->readPublicScalar($conversion, 'id'),
            $this->readPublicScalar($conversion, 'affiliateId'),
            $this->readPublicScalar($conversion, 'affiliateCode'),
            $this->readPublicScalar($conversion, 'ownerType'),
            $this->readPublicScalar($conversion, 'ownerId'),
        );

        if (! $conversionModel instanceof Model) {
            return null;
        }

        $trackedProperty = $this->resolveTrackedPropertyForAffiliateModel($conversionModel);

        if ($trackedProperty === null) {
            return null;
        }

        $attributionModel = $this->resolveAffiliateModel(
            'AIArmada\\Affiliates\\Models\\AffiliateAttribution',
            $this->stringValue($conversionModel->getAttribute('affiliate_attribution_id')),
            $this->stringValue($conversionModel->getAttribute('affiliate_id')),
            $this->stringValue($conversionModel->getAttribute('affiliate_code')),
            $this->stringValue($conversionModel->getAttribute('owner_type')),
            $this->stringValue($conversionModel->getAttribute('owner_id')),
        );
        $subjectIdentifier = $this->stringValue($conversionModel->getAttribute('subject_identifier'))
            ?? $this->readPublicScalar($conversion, 'subjectIdentifier');
        $subjectInstance = $this->stringValue($conversionModel->getAttribute('subject_instance'))
            ?? $this->readPublicScalar($conversion, 'subjectInstance');
        $cartIdentifier = $this->stringValue($conversionModel->getAttribute('cart_identifier'))
            ?? $this->readPublicScalar($conversion, 'cartIdentifier')
            ?? $subjectIdentifier;
        $cartInstance = $this->stringValue($conversionModel->getAttribute('cart_instance'))
            ?? $this->readPublicScalar($conversion, 'cartInstance')
            ?? $subjectInstance
            ?? 'default';
        $revenueMinor = $this->resolveAffiliateRevenueMinor($conversionModel);

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.conversion_event_name', 'affiliate.conversion.recorded'),
            'event_category' => (string) config('signals.integrations.affiliates.conversion_event_category', 'conversion'),
            'external_id' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('user_id')) : null,
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->buildAffiliateSessionIdentifier($cartIdentifier, $cartInstance),
            'occurred_at' => $this->timestampValue($conversionModel->getAttribute('occurred_at') ?? $conversionModel->getAttribute('created_at')),
            'path' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('landing_url')) : null,
            'url' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('landing_url')) : null,
            'referrer' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('referrer_url')) : null,
            'source' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('source')) : null,
            'medium' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('medium')) : null,
            'campaign' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('campaign')) : null,
            'content' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('content')) : null,
            'term' => $attributionModel instanceof Model ? $this->stringValue($attributionModel->getAttribute('term')) : null,
            'revenue_minor' => $revenueMinor,
            'currency' => $this->stringValue($conversionModel->getAttribute('commission_currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'conversion_id' => $this->stringValue($conversionModel->getKey()),
                'affiliate_id' => $this->stringValue($conversionModel->getAttribute('affiliate_id'))
                    ?? $this->readPublicScalar($conversion, 'affiliateId'),
                'affiliate_code' => $this->stringValue($conversionModel->getAttribute('affiliate_code'))
                    ?? $this->readPublicScalar($conversion, 'affiliateCode'),
                'attribution_id' => $this->stringValue($conversionModel->getAttribute('affiliate_attribution_id')),
                'subject_identifier' => $subjectIdentifier,
                'subject_instance' => $subjectInstance,
                'cart_identifier' => $this->stringValue($conversionModel->getAttribute('cart_identifier')),
                'cart_instance' => $this->stringValue($conversionModel->getAttribute('cart_instance')),
                'voucher_code' => $this->stringValue($conversionModel->getAttribute('voucher_code')),
                'external_reference' => $this->stringValue($conversionModel->getAttribute('external_reference'))
                    ?? $this->readPublicScalar($conversion, 'externalReference'),
                'order_reference' => $this->stringValue($conversionModel->getAttribute('order_reference')),
                'conversion_type' => $this->stringValue($conversionModel->getAttribute('conversion_type'))
                    ?? $this->readPublicScalar($conversion, 'conversionType'),
                'subtotal_minor' => $conversionModel->getAttribute('subtotal_minor'),
                'value_minor' => $revenueMinor,
                'total_minor' => $conversionModel->getAttribute('total_minor'),
                'commission_minor' => $conversionModel->getAttribute('commission_minor'),
                'status' => $this->normalizeStateValue($conversionModel->getAttribute('status')),
                'channel' => $this->stringValue($conversionModel->getAttribute('channel')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordCartEvent(object $cart, string $eventName, array $properties = []): ?SignalEvent
    {
        $trackedProperty = $this->resolveTrackedPropertyForCart($cart);

        if ($trackedProperty === null) {
            return null;
        }

        $cartIdentifier = $this->callStringMethod($cart, 'getIdentifier');
        $instanceName = $this->callStringMethod($cart, 'instance') ?? 'default';
        $sessionIdentifier = $this->buildCartSessionIdentifier($cartIdentifier, $instanceName);

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => (string) config('signals.integrations.cart.event_category', 'cart'),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $sessionIdentifier,
            'occurred_at' => $this->timestampValue($this->callMethod($cart, 'getUpdatedAt') ?? $this->callMethod($cart, 'getCreatedAt')),
            'revenue_minor' => 0,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter(array_merge([
                'cart_id' => $this->callStringMethod($cart, 'getId'),
                'cart_identifier' => $cartIdentifier,
                'cart_instance' => $instanceName,
                'cart_total_minor' => $this->callIntMethod($cart, 'getRawTotal'),
                'total_quantity' => $this->callIntMethod($cart, 'getTotalQuantity'),
                'unique_item_count' => $this->callIntMethod($cart, 'countItems'),
            ], $properties), static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function recordVoucherEvent(object $cart, object $voucher, string $eventName): ?SignalEvent
    {
        $trackedProperty = $this->resolveTrackedPropertyForCart($cart);

        if ($trackedProperty === null) {
            return null;
        }

        $cartIdentifier = $this->callStringMethod($cart, 'getIdentifier');
        $instanceName = $this->callStringMethod($cart, 'instance') ?? 'default';
        $sessionIdentifier = $this->buildCartSessionIdentifier($cartIdentifier, $instanceName);

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => (string) config('signals.integrations.vouchers.event_category', 'promotion'),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $sessionIdentifier,
            'occurred_at' => $this->timestampValue($this->callMethod($cart, 'getUpdatedAt') ?? $this->callMethod($cart, 'getCreatedAt')),
            'revenue_minor' => 0,
            'currency' => $this->readPublicScalar($voucher, 'currency') ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'cart_id' => $this->callStringMethod($cart, 'getId'),
                'cart_identifier' => $cartIdentifier,
                'cart_instance' => $instanceName,
                'cart_total_minor' => $this->callIntMethod($cart, 'getRawTotal'),
                'voucher_id' => $this->readPublicScalar($voucher, 'id'),
                'voucher_code' => $this->readPublicScalar($voucher, 'code'),
                'voucher_name' => $this->readPublicScalar($voucher, 'name'),
                'voucher_type' => $this->resolveVoucherType($voucher),
                'voucher_value' => $this->readPublicInt($voucher, 'value'),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function resolveTrackedPropertyForCart(object $cart): ?TrackedProperty
    {
        $storage = $this->callMethod($cart, 'storage');

        if (! is_object($storage) || ! method_exists($storage, 'getOwnerType') || ! method_exists($storage, 'getOwnerId')) {
            return null;
        }

        $ownerType = $storage->getOwnerType();
        $ownerId = $storage->getOwnerId();

        return $this->trackedPropertyResolver->resolveForOwnerReference(
            is_string($ownerType) ? $ownerType : null,
            is_string($ownerId) || is_int($ownerId) ? $ownerId : null,
            null,
            'cart',
        );
    }

    private function recordFilamentCartEvent(object $event, string $eventName): ?SignalEvent
    {
        $ownerType = $this->readPublicScalar($event, 'ownerType');
        $ownerId = $this->readPublicScalar($event, 'ownerId');
        $trackedProperty = $this->trackedPropertyResolver->resolveForOwnerReference($ownerType, $ownerId, null, 'filament_cart');

        if ($trackedProperty === null) {
            return null;
        }

        $cartIdentifier = $this->readPublicScalar($event, 'cartIdentifier');
        $cartInstance = $this->readPublicScalar($event, 'cartInstance') ?? 'default';

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => (string) config('signals.integrations.filament_cart.event_category', 'cart'),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->buildCartSessionIdentifier($cartIdentifier, $cartInstance),
            'occurred_at' => $this->readPublicScalar($event, 'occurredAt'),
            'revenue_minor' => $this->readPublicInt($event, 'totalMinor') ?? 0,
            'currency' => $this->readPublicScalar($event, 'currency') ?? (string) config('signals.defaults.currency', 'MYR'),
            'source_event_id' => $this->readPublicScalar($event, 'sourceEventId'),
            'properties' => array_filter([
                'source_event_id' => $this->readPublicScalar($event, 'sourceEventId'),
                'cart_id' => $this->readPublicScalar($event, 'cartId'),
                'cart_identifier' => $cartIdentifier,
                'cart_instance' => $cartInstance,
                'cart_total_minor' => $this->readPublicInt($event, 'totalMinor'),
                'subtotal_minor' => $this->readPublicInt($event, 'subtotalMinor'),
                'total_quantity' => $this->readPublicInt($event, 'totalQuantity'),
                'unique_item_count' => $this->readPublicInt($event, 'uniqueItemCount'),
                'item_count' => $this->readPublicInt($event, 'itemCount'),
                'currency' => $this->readPublicScalar($event, 'currency'),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function resolveTrackedPropertyForAffiliateModel(Model $model): ?TrackedProperty
    {
        return $this->trackedPropertyResolver->resolveForOwnerReference(
            $this->stringValue($model->getAttribute('owner_type')),
            $model->getAttribute('owner_id'),
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    private function enrichProperties(Model $source, TrackedProperty $trackedProperty, array $properties): ?array
    {
        $baseProperties = array_filter($properties, static fn (mixed $value): bool => $value !== null);

        if (! app()->bound('growth.signal_event_property_enricher')) {
            return $baseProperties === [] ? null : $baseProperties;
        }

        $enricher = app('growth.signal_event_property_enricher');

        if (! is_object($enricher) || ! method_exists($enricher, 'handle')) {
            return $baseProperties === [] ? null : $baseProperties;
        }

        $handleEnrichment = fn (): mixed => $enricher->handle($source, $trackedProperty, $baseProperties);

        $enriched = OwnerContext::hasOverride()
            ? $handleEnrichment()
            : OwnerContext::withOwner($trackedProperty->owner, $handleEnrichment);

        if (! is_array($enriched)) {
            return $baseProperties === [] ? null : $baseProperties;
        }

        return $enriched === [] ? null : $enriched;
    }

    private function cartIdForOrder(Model $order): ?string
    {
        $cartId = $this->stringValue($this->attributeValue($order, 'cart_id'));

        if ($cartId !== null) {
            return $cartId;
        }

        return $this->orderMetadataValue($order, 'cart_id');
    }

    private function checkoutSessionIdForOrder(Model $order): ?string
    {
        return $this->orderMetadataValue($order, 'checkout_session_id');
    }

    private function orderMetadataValue(Model $order, string $key): ?string
    {
        $metadata = $this->attributeValue($order, 'metadata');

        if (! is_array($metadata)) {
            return null;
        }

        return $this->stringValue(data_get($metadata, $key));
    }

    private function growthVisitorIdFromCheckoutSession(Model $session): ?string
    {
        $paymentData = $this->attributeValue($session, 'payment_data');

        if (is_array($paymentData)) {
            $value = $this->stringValue(data_get($paymentData, 'growth_visitor_id'));

            if ($value !== null) {
                return $value;
            }
        }

        $billingData = $this->attributeValue($session, 'billing_data');

        if (is_array($billingData)) {
            $value = $this->stringValue(data_get($billingData, 'metadata.growth_visitor_id'));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function growthVisitorIdFromOrder(Model $order): ?string
    {
        return $this->orderMetadataValue($order, 'payment_data.growth_visitor_id')
            ?? $this->orderMetadataValue($order, 'growth_visitor_id');
    }

    private function isEventRecordingEnabled(string $eventName): bool
    {
        $value = config('signals.recording.events.' . $eventName);

        if ($value === null) {
            return true;
        }

        return (bool) $value;
    }

    public function recordOfferCreated(object $offer): ?SignalEvent
    {
        return $this->recordAffiliateNetworkEvent($offer, 'offer.created', 'affiliate_network');
    }

    public function recordOfferUpdated(object $offer): ?SignalEvent
    {
        return $this->recordAffiliateNetworkEvent($offer, 'offer.updated', 'affiliate_network');
    }

    public function recordApplicationSubmitted(object $application): ?SignalEvent
    {
        return $this->recordAffiliateNetworkEvent($application, 'application.submitted', 'affiliate_network');
    }

    public function recordApplicationApproved(object $application): ?SignalEvent
    {
        return $this->recordAffiliateNetworkEvent($application, 'application.approved', 'affiliate_network');
    }

    public function recordNetworkConversionRecorded(object $link, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->recordAffiliateNetworkEvent($link, 'network.conversion.recorded', 'affiliate_network');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordSignal(string $eventName, array $data = []): ?SignalEvent
    {
        $trackedProperty = $this->trackedPropertyResolver->resolveForOwnerReference(
            $data['owner_type'] ?? null,
            $data['owner_id'] ?? null,
            null,
        );

        if ($trackedProperty === null) {
            return null;
        }

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => $data['event_category'] ?? 'commerce',
            'occurred_at' => $this->timestampValue($data['occurred_at'] ?? now()),
            'revenue_minor' => (int) ($data['revenue_minor'] ?? 0),
            'currency' => (string) ($data['currency'] ?? config('signals.defaults.currency', 'MYR')),
            'external_id' => $data['external_id'] ?? null,
            'anonymous_id' => $data['anonymous_id'] ?? null,
            'properties' => $data['properties'] ?? array_filter($data, static fn (string $key): bool => ! in_array($key, ['event_category', 'occurred_at', 'revenue_minor', 'currency', 'external_id', 'anonymous_id', 'owner_type', 'owner_id', 'properties'], true), ARRAY_FILTER_USE_KEY),
        ]);
    }

    private function recordAffiliateNetworkEvent(object $subject, string $eventName, string $category): ?SignalEvent
    {
        $ownerType = $this->readPublicScalar($subject, 'owner_type') ?? $this->readPublicScalar($subject, 'ownerType');
        $ownerId = $this->readPublicScalar($subject, 'owner_id') ?? $this->readPublicScalar($subject, 'ownerId');

        $trackedProperty = $this->trackedPropertyResolver->resolveForOwnerReference(
            $ownerType,
            $ownerId,
            null,
            'affiliate_network',
        );

        if ($trackedProperty === null) {
            return null;
        }

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => $category,
            'occurred_at' => $this->timestampValue(now()),
            'revenue_minor' => 0,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'subject_id' => $this->readPublicScalar($subject, 'id'),
                'status' => $this->readPublicScalar($subject, 'status'),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function attributeValue(Model $model, string $attribute): mixed
    {
        if (! array_key_exists($attribute, $model->getAttributes())) {
            return null;
        }

        return $model->getAttribute($attribute);
    }

    private function buildCartSessionIdentifier(?string $cartIdentifier, string $instanceName): ?string
    {
        if ($cartIdentifier === null || $cartIdentifier === '') {
            return null;
        }

        return 'cart:' . $instanceName . ':' . $cartIdentifier;
    }

    private function buildAffiliateSessionIdentifier(?string $identifier, string $instanceName): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return 'affiliate:' . $instanceName . ':' . $identifier;
    }

    private function resolveAffiliateModel(
        string $modelClass,
        ?string $identifier,
        ?string $expectedAffiliateId = null,
        ?string $expectedAffiliateCode = null,
        ?string $expectedOwnerType = null,
        string | int | null $expectedOwnerId = null,
    ): ?Model {
        if ($identifier === null || $identifier === '' || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $query = $modelClass::query();

        if (method_exists($modelClass, 'scopeWithoutOwnerScope')) {
            /** @var mixed $ownerScopedQuery */
            $ownerScopedQuery = $query;
            $query = $ownerScopedQuery->withoutOwnerScope();
        }

        if ($expectedAffiliateId !== null && $expectedAffiliateId !== '') {
            $query->where('affiliate_id', $expectedAffiliateId);
        }

        if ($expectedAffiliateCode !== null && $expectedAffiliateCode !== '') {
            $query->where('affiliate_code', $expectedAffiliateCode);
        }

        if ($expectedOwnerType !== null && $expectedOwnerType !== '') {
            $query->where('owner_type', $expectedOwnerType);
        }

        if ($expectedOwnerId !== null && $expectedOwnerId !== '') {
            $query->where('owner_id', $expectedOwnerId);
        }

        $model = $query->find($identifier);

        return $model instanceof Model ? $model : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function timestampValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) ? $value : null;
    }

    private function normalizeStateValue(mixed $value): ?string
    {
        if (is_object($value) && method_exists($value, 'getValue')) {
            $resolved = $value->getValue();

            return is_scalar($resolved) ? (string) $resolved : null;
        }

        return $this->stringValue($value);
    }

    private function resolveAffiliateRevenueMinor(Model $conversionModel): int
    {
        $valueMinor = (int) $conversionModel->getRawOriginal('value_minor');

        if ($valueMinor !== 0) {
            return $valueMinor;
        }

        return (int) $conversionModel->getRawOriginal('total_minor');
    }

    private function callMethod(object $object, string $method): mixed
    {
        if (! method_exists($object, $method)) {
            return null;
        }

        return $object->{$method}();
    }

    private function callStringMethod(object $object, string $method): ?string
    {
        return $this->stringValue($this->callMethod($object, $method));
    }

    private function callIntMethod(object $object, string $method): ?int
    {
        $value = $this->callMethod($object, $method);

        return is_int($value) ? $value : null;
    }

    private function readPublicScalar(object $object, string $property): ?string
    {
        if (! property_exists($object, $property)) {
            return null;
        }

        $value = $object->{$property};

        return is_scalar($value) ? (string) $value : null;
    }

    private function readPublicInt(object $object, string $property): ?int
    {
        if (! property_exists($object, $property)) {
            return null;
        }

        $value = $object->{$property};

        return is_int($value) ? $value : null;
    }

    private function calculateLineTotal(object $item): ?int
    {
        $price = $this->readPublicInt($item, 'price');
        $quantity = $this->readPublicInt($item, 'quantity');

        if ($price === null || $quantity === null) {
            return null;
        }

        return $price * $quantity;
    }

    private function resolveVoucherType(object $voucher): ?string
    {
        if (! property_exists($voucher, 'type')) {
            return null;
        }

        $type = $voucher->type;

        if (is_object($type) && property_exists($type, 'value') && is_scalar($type->value)) {
            return (string) $type->value;
        }

        return is_scalar($type) ? (string) $type : null;
    }
}
