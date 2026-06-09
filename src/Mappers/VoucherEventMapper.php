<?php

declare(strict_types=1);

namespace AIArmada\Signals\Mappers;

use AIArmada\Signals\Contracts\MapCommerceEventToSignalInterface;
use AIArmada\Vouchers\Events\VoucherApplied;
use AIArmada\Vouchers\Events\VoucherRemoved;

final class VoucherEventMapper implements MapCommerceEventToSignalInterface
{
    public function map(object $event): ?array
    {
        $voucher = $event->voucher ?? null;

        if (! is_object($voucher)) {
            return null;
        }

        $voucherCode = method_exists($voucher, 'getCode') ? $voucher->getCode()
            : (property_exists($voucher, 'code') ? $voucher->code : null);

        return match ($event::class) {
            VoucherApplied::class => [
                'event_type' => 'voucher_applied',
                'data' => [
                    'code' => $voucherCode,
                ],
            ],
            VoucherRemoved::class => [
                'event_type' => 'voucher_removed',
                'data' => [
                    'code' => $voucherCode,
                ],
            ],
            default => null,
        };
    }

    public function handles(): string
    {
        return VoucherApplied::class;
    }

    /**
     * @return array<class-string>
     */
    public static function handledEvents(): array
    {
        return [
            VoucherApplied::class,
            VoucherRemoved::class,
        ];
    }
}
