<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordVoucherRemovedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $cart = $event->cart ?? null;
        $voucher = $event->voucher ?? null;

        if (! is_object($cart) || ! is_object($voucher)) {
            return;
        }

        $this->recorder->recordVoucherRemoved($cart, $voucher);
    }
}
