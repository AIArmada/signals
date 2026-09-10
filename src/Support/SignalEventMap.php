<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

final class SignalEventMap
{
    /**
     * @return array{method: string, arguments: list<array{property: string|null, type: string}>}|null
     */
    public static function for(string $eventClass): ?array
    {
        return [
            'AIArmada\\Affiliates\\Events\\AffiliateAttributed' => [
                'method' => 'recordAffiliateAttributed',
                'arguments' => [['property' => 'attribution', 'type' => 'object']],
            ],
            'AIArmada\\Affiliates\\Events\\AffiliateConversionRecorded' => [
                'method' => 'recordAffiliateConversionRecorded',
                'arguments' => [['property' => 'conversion', 'type' => 'object']],
            ],
            'AIArmada\\AffiliateNetwork\\Events\\OfferCreated' => [
                'method' => 'recordOfferCreated',
                'arguments' => [['property' => 'offer', 'type' => 'object']],
            ],
            'AIArmada\\AffiliateNetwork\\Events\\OfferUpdated' => [
                'method' => 'recordOfferUpdated',
                'arguments' => [['property' => 'offer', 'type' => 'object']],
            ],
            'AIArmada\\AffiliateNetwork\\Events\\ApplicationSubmitted' => [
                'method' => 'recordApplicationSubmitted',
                'arguments' => [['property' => 'application', 'type' => 'object']],
            ],
            'AIArmada\\AffiliateNetwork\\Events\\ApplicationApproved' => [
                'method' => 'recordApplicationApproved',
                'arguments' => [['property' => 'application', 'type' => 'object']],
            ],
            'AIArmada\\AffiliateNetwork\\Events\\NetworkConversionRecorded' => [
                'method' => 'recordNetworkConversionRecorded',
                'arguments' => [
                    ['property' => 'link', 'type' => 'object'],
                    ['property' => 'revenueMinor', 'type' => 'numeric_int'],
                ],
            ],
            'AIArmada\\Cart\\Events\\ItemAdded' => [
                'method' => 'recordCartItemAdded',
                'arguments' => [
                    ['property' => 'cart', 'type' => 'object'],
                    ['property' => 'item', 'type' => 'object'],
                ],
            ],
            'AIArmada\\Cart\\Events\\ItemRemoved' => [
                'method' => 'recordCartItemRemoved',
                'arguments' => [
                    ['property' => 'cart', 'type' => 'object'],
                    ['property' => 'item', 'type' => 'object'],
                ],
            ],
            'AIArmada\\Cart\\Events\\CartCleared' => [
                'method' => 'recordCartCleared',
                'arguments' => [['property' => 'cart', 'type' => 'object']],
            ],
            'AIArmada\\Cart\\Events\\CartSnapshotSynced' => [
                'method' => 'recordCartSnapshotSynced',
                'arguments' => [['property' => null, 'type' => 'event']],
            ],
            'AIArmada\\Cart\\Events\\CartCheckoutStarted' => [
                'method' => 'recordCartCheckoutStarted',
                'arguments' => [['property' => null, 'type' => 'event']],
            ],
            'AIArmada\\Cart\\Events\\CartAbandoned' => [
                'method' => 'recordCartAbandoned',
                'arguments' => [['property' => null, 'type' => 'event']],
            ],
            'AIArmada\\Cart\\Events\\HighValueCartDetected' => [
                'method' => 'recordHighValueCartDetected',
                'arguments' => [['property' => null, 'type' => 'event']],
            ],
            'AIArmada\\Checkout\\Events\\CheckoutStarted' => [
                'method' => 'recordCheckoutStarted',
                'arguments' => [['property' => 'session', 'type' => 'model']],
            ],
            'AIArmada\\Checkout\\Events\\CheckoutCompleted' => [
                'method' => 'recordCheckoutCompleted',
                'arguments' => [['property' => 'session', 'type' => 'model']],
            ],
            'AIArmada\\Orders\\Events\\OrderPaid' => [
                'method' => 'recordOrderPaid',
                'arguments' => [
                    ['property' => 'order', 'type' => 'model'],
                    ['property' => 'transactionId', 'type' => 'scalar'],
                    ['property' => 'gateway', 'type' => 'scalar'],
                ],
            ],
            'AIArmada\\Orders\\Events\\OrderRefunded' => [
                'method' => 'recordOrderRefunded',
                'arguments' => [
                    ['property' => 'order', 'type' => 'model'],
                    ['property' => 'amount', 'type' => 'numeric_int'],
                    ['property' => 'reason', 'type' => 'scalar'],
                ],
            ],
            'AIArmada\\Vouchers\\Events\\VoucherApplied' => [
                'method' => 'recordVoucherApplied',
                'arguments' => [
                    ['property' => 'cart', 'type' => 'object'],
                    ['property' => 'voucher', 'type' => 'object'],
                ],
            ],
            'AIArmada\\Vouchers\\Events\\VoucherRemoved' => [
                'method' => 'recordVoucherRemoved',
                'arguments' => [
                    ['property' => 'cart', 'type' => 'object'],
                    ['property' => 'voucher', 'type' => 'object'],
                ],
            ],
        ][$eventClass] ?? null;
    }
}
