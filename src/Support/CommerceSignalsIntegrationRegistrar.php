<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\Signals\Listeners\RecordAffiliateAttributedSignal;
use AIArmada\Signals\Listeners\RecordAffiliateConversionRecordedSignal;
use AIArmada\Signals\Listeners\RecordCartClearedSignal;
use AIArmada\Signals\Listeners\RecordCartItemAddedSignal;
use AIArmada\Signals\Listeners\RecordCartItemRemovedSignal;
use AIArmada\Signals\Listeners\RecordCheckoutCompletedSignal;
use AIArmada\Signals\Listeners\RecordCheckoutStartedSignal;
use AIArmada\Signals\Listeners\RecordOrderPaidSignal;
use AIArmada\Signals\Listeners\RecordVoucherAppliedSignal;
use AIArmada\Signals\Listeners\RecordVoucherRemovedSignal;
use Illuminate\Support\Facades\Event;

final class CommerceSignalsIntegrationRegistrar
{
    public function boot(): void
    {
        $this->bootAffiliatesIntegration();
        $this->bootCartIntegration();
        $this->bootCheckoutIntegration();
        $this->bootOrdersIntegration();
        $this->bootVoucherIntegration();
    }

    private function bootAffiliatesIntegration(): void
    {
        if (! config('signals.integrations.affiliates.enabled', true)) {
            return;
        }

        if (config('signals.integrations.affiliates.listen_for_attributed', true)) {
            $this->listenIfAvailable('AIArmada\\Affiliates\\Events\\AffiliateAttributed', RecordAffiliateAttributedSignal::class);
        }

        if (config('signals.integrations.affiliates.listen_for_conversion_recorded', true)) {
            $this->listenIfAvailable('AIArmada\\Affiliates\\Events\\AffiliateConversionRecorded', RecordAffiliateConversionRecordedSignal::class);
        }
    }

    private function bootCartIntegration(): void
    {
        if (! config('signals.integrations.cart.enabled', true)) {
            return;
        }

        if (config('signals.integrations.cart.listen_for_item_added', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\ItemAdded', RecordCartItemAddedSignal::class);
        }

        if (config('signals.integrations.cart.listen_for_item_removed', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\ItemRemoved', RecordCartItemRemovedSignal::class);
        }

        if (config('signals.integrations.cart.listen_for_cleared', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\CartCleared', RecordCartClearedSignal::class);
        }
    }

    private function bootCheckoutIntegration(): void
    {
        if (! config('signals.integrations.checkout.enabled', true)) {
            return;
        }

        if (config('signals.integrations.checkout.listen_for_started', true)) {
            $this->listenIfAvailable('AIArmada\\Checkout\\Events\\CheckoutStarted', RecordCheckoutStartedSignal::class);
        }

        if (! config('signals.integrations.checkout.listen_for_completed', true)) {
            return;
        }

        $this->listenIfAvailable('AIArmada\\Checkout\\Events\\CheckoutCompleted', RecordCheckoutCompletedSignal::class);
    }

    private function bootOrdersIntegration(): void
    {
        if (! config('signals.integrations.orders.enabled', true)) {
            return;
        }

        if (! config('signals.integrations.orders.listen_for_paid', true)) {
            return;
        }

        $this->listenIfAvailable('AIArmada\\Orders\\Events\\OrderPaid', RecordOrderPaidSignal::class);
    }

    private function bootVoucherIntegration(): void
    {
        if (! config('signals.integrations.vouchers.enabled', true)) {
            return;
        }

        if (config('signals.integrations.vouchers.listen_for_applied', true)) {
            $this->listenIfAvailable('AIArmada\\Vouchers\\Events\\VoucherApplied', RecordVoucherAppliedSignal::class);
        }

        if (config('signals.integrations.vouchers.listen_for_removed', true)) {
            $this->listenIfAvailable('AIArmada\\Vouchers\\Events\\VoucherRemoved', RecordVoucherRemovedSignal::class);
        }
    }

    private function listenIfAvailable(string $eventClass, string $listenerClass): void
    {
        if (! class_exists($eventClass)) {
            return;
        }

        Event::listen($eventClass, $listenerClass);
    }
}
