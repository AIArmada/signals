<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

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
        $trackedProperty = $this->trackedPropertyResolver->resolveForModel($session);

        if ($trackedProperty === null) {
            return null;
        }

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.checkout.event_name', 'checkout.completed'),
            'event_category' => (string) config('signals.integrations.checkout.event_category', 'checkout'),
            'external_id' => $this->stringValue($session->getAttribute('customer_id')),
            'anonymous_id' => $this->stringValue($session->getAttribute('cart_id')),
            'occurred_at' => $this->timestampValue($session->getAttribute('completed_at') ?? $session->getAttribute('updated_at')),
            'revenue_minor' => (int) ($session->getAttribute('grand_total') ?? 0),
            'currency' => $this->stringValue($session->getAttribute('currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'checkout_session_id' => $this->stringValue($session->getKey()),
                'order_id' => $this->stringValue($session->getAttribute('order_id')),
                'payment_gateway' => $this->stringValue($session->getAttribute('selected_payment_gateway')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordCheckoutStarted(Model $session): ?SignalEvent
    {
        $trackedProperty = $this->trackedPropertyResolver->resolveForModel($session);

        if ($trackedProperty === null) {
            return null;
        }

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.checkout.started_event_name', 'checkout.started'),
            'event_category' => (string) config('signals.integrations.checkout.event_category', 'checkout'),
            'external_id' => $this->stringValue($session->getAttribute('customer_id')),
            'anonymous_id' => $this->stringValue($session->getAttribute('cart_id')),
            'occurred_at' => $this->timestampValue($session->getAttribute('created_at') ?? $session->getAttribute('updated_at')),
            'revenue_minor' => (int) ($session->getAttribute('grand_total') ?? 0),
            'currency' => $this->stringValue($session->getAttribute('currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'checkout_session_id' => $this->stringValue($session->getKey()),
                'payment_gateway' => $this->stringValue($session->getAttribute('selected_payment_gateway')),
                'shipping_method' => $this->stringValue($session->getAttribute('selected_shipping_method')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordOrderPaid(Model $order, ?string $transactionId = null, ?string $gateway = null): ?SignalEvent
    {
        $trackedProperty = $this->trackedPropertyResolver->resolveForModel($order);

        if ($trackedProperty === null) {
            return null;
        }

        return $this->ingestSignalEvent->handle($trackedProperty, [
            'event_name' => (string) config('signals.integrations.orders.event_name', 'order.paid'),
            'event_category' => (string) config('signals.integrations.orders.event_category', 'conversion'),
            'external_id' => $this->stringValue($order->getAttribute('customer_id')),
            'occurred_at' => $this->timestampValue($order->getAttribute('paid_at') ?? $order->getAttribute('updated_at')),
            'revenue_minor' => (int) ($order->getAttribute('grand_total') ?? 0),
            'currency' => $this->stringValue($order->getAttribute('currency')) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'order_id' => $this->stringValue($order->getKey()),
                'order_number' => $this->stringValue($order->getAttribute('order_number')),
                'gateway' => $gateway,
                'transaction_id' => $transactionId,
            ], static fn (mixed $value): bool => $value !== null),
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
        );
    }

    private function resolveTrackedPropertyForAffiliateModel(Model $model): ?TrackedProperty
    {
        return $this->trackedPropertyResolver->resolveForOwnerReference(
            $this->stringValue($model->getAttribute('owner_type')),
            $model->getAttribute('owner_id'),
        );
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
