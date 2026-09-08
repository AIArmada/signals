<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;

final class VoucherSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordApplied(object $cart, object $voucher): ?SignalEvent
    {
        return $this->record(
            cart: $cart,
            voucher: $voucher,
            eventName: (string) config('signals.integrations.vouchers.applied_event_name', 'voucher.applied'),
        );
    }

    public function recordRemoved(object $cart, object $voucher): ?SignalEvent
    {
        return $this->record(
            cart: $cart,
            voucher: $voucher,
            eventName: (string) config('signals.integrations.vouchers.removed_event_name', 'voucher.removed'),
        );
    }

    private function record(object $cart, object $voucher, string $eventName): ?SignalEvent
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

        $voucherType = $this->support->optionalPublicScalar($voucher, 'type');

        if (property_exists($voucher, 'type') && is_object($voucher->type) && property_exists($voucher->type, 'value')) {
            $voucherType = $this->support->stringValue($voucher->type->value);
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => (string) config('signals.integrations.vouchers.event_category', 'promotion'),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->support->buildCartSessionIdentifier($cartIdentifier, $instanceName),
            'occurred_at' => $occurredAt,
            'revenue_minor' => 0,
            'currency' => $this->support->optionalPublicScalar($voucher, 'currency')
                ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'cart_id' => $this->support->stringValue($this->support->requiredMethod($cart, 'getId')),
                'cart_identifier' => $cartIdentifier,
                'cart_instance' => $instanceName,
                'cart_total_minor' => $this->support->requiredIntMethod($cart, 'getRawTotal'),
                'voucher_id' => $this->support->requiredPublicScalar($voucher, 'id'),
                'voucher_code' => $this->support->requiredPublicScalar($voucher, 'code'),
                'voucher_name' => $this->support->requiredPublicScalar($voucher, 'name'),
                'voucher_type' => $voucherType,
                'voucher_value' => $this->support->requiredPublicInt($voucher, 'value'),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
