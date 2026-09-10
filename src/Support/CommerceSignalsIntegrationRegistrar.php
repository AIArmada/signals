<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\Signals\Listeners\RecordCommerceSignal;
use Illuminate\Support\Facades\Event;

final class CommerceSignalsIntegrationRegistrar
{
    public function boot(): void
    {
        $this->bootAffiliatesIntegration();
        $this->bootAffiliateNetworkIntegration();
        $this->bootCartIntegration();
        $this->bootFilamentCartIntegration();
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
            $this->listenIfAvailable('AIArmada\\Affiliates\\Events\\AffiliateAttributed');
        }

        if (config('signals.integrations.affiliates.listen_for_conversion_recorded', true)) {
            $this->listenIfAvailable('AIArmada\\Affiliates\\Events\\AffiliateConversionRecorded');
        }
    }

    private function bootCartIntegration(): void
    {
        if (! config('signals.integrations.cart.enabled', true)) {
            return;
        }

        if (config('signals.integrations.cart.listen_for_item_added', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\ItemAdded');
        }

        if (config('signals.integrations.cart.listen_for_item_removed', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\ItemRemoved');
        }

        if (config('signals.integrations.cart.listen_for_cleared', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\CartCleared');
        }
    }

    private function bootFilamentCartIntegration(): void
    {
        if (! config('signals.integrations.filament_cart.enabled', false)) {
            return;
        }

        if (config('signals.integrations.filament_cart.listen_for_snapshot_synced', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\CartSnapshotSynced');
        }

        if (config('signals.integrations.filament_cart.listen_for_checkout_started', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\CartCheckoutStarted');
        }

        if (config('signals.integrations.filament_cart.listen_for_abandoned', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\CartAbandoned');
        }

        if (config('signals.integrations.filament_cart.listen_for_high_value_detected', true)) {
            $this->listenIfAvailable('AIArmada\\Cart\\Events\\HighValueCartDetected');
        }
    }

    private function bootCheckoutIntegration(): void
    {
        if (! config('signals.integrations.checkout.enabled', true)) {
            return;
        }

        if (config('signals.integrations.checkout.listen_for_started', true)) {
            $this->listenIfAvailable('AIArmada\\Checkout\\Events\\CheckoutStarted');
        }

        if (! config('signals.integrations.checkout.listen_for_completed', true)) {
            return;
        }

        $this->listenIfAvailable('AIArmada\\Checkout\\Events\\CheckoutCompleted');
    }

    private function bootOrdersIntegration(): void
    {
        if (! config('signals.integrations.orders.enabled', true)) {
            return;
        }

        if (! config('signals.integrations.orders.listen_for_paid', true)) {
            if (! config('signals.integrations.orders.listen_for_refunded', true)) {
                return;
            }
        } else {
            $this->listenIfAvailable('AIArmada\\Orders\\Events\\OrderPaid');
        }

        if (! config('signals.integrations.orders.listen_for_refunded', true)) {
            return;
        }

        $this->listenIfAvailable('AIArmada\\Orders\\Events\\OrderRefunded');
    }

    private function bootVoucherIntegration(): void
    {
        if (! config('signals.integrations.vouchers.enabled', true)) {
            return;
        }

        if (config('signals.integrations.vouchers.listen_for_applied', true)) {
            $this->listenIfAvailable('AIArmada\\Vouchers\\Events\\VoucherApplied');
        }

        if (config('signals.integrations.vouchers.listen_for_removed', true)) {
            $this->listenIfAvailable('AIArmada\\Vouchers\\Events\\VoucherRemoved');
        }
    }

    private function bootAffiliateNetworkIntegration(): void
    {
        if (! config('signals.integrations.affiliate_network.enabled', true)) {
            return;
        }

        if (config('signals.integrations.affiliate_network.listen_for_offer_created', true)) {
            $this->listenIfAvailable('AIArmada\\AffiliateNetwork\\Events\\OfferCreated');
        }

        if (config('signals.integrations.affiliate_network.listen_for_offer_updated', true)) {
            $this->listenIfAvailable('AIArmada\\AffiliateNetwork\\Events\\OfferUpdated');
        }

        if (config('signals.integrations.affiliate_network.listen_for_application_submitted', true)) {
            $this->listenIfAvailable('AIArmada\\AffiliateNetwork\\Events\\ApplicationSubmitted');
        }

        if (config('signals.integrations.affiliate_network.listen_for_application_approved', true)) {
            $this->listenIfAvailable('AIArmada\\AffiliateNetwork\\Events\\ApplicationApproved');
        }

        if (config('signals.integrations.affiliate_network.listen_for_network_conversion_recorded', true)) {
            $this->listenIfAvailable('AIArmada\\AffiliateNetwork\\Events\\NetworkConversionRecorded');
        }
    }

    private function listenIfAvailable(string $eventClass): void
    {
        if (! class_exists($eventClass)) {
            return;
        }

        Event::listen($eventClass, RecordCommerceSignal::class);
    }
}
