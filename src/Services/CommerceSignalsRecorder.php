<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Actions\IngestSignalEvent;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Services\Recorders\AffiliateNetworkSignalRecorder;
use AIArmada\Signals\Services\Recorders\AffiliateSignalRecorder;
use AIArmada\Signals\Services\Recorders\CartSignalRecorder;
use AIArmada\Signals\Services\Recorders\CheckoutSignalRecorder;
use AIArmada\Signals\Services\Recorders\FilamentCartSignalRecorder;
use AIArmada\Signals\Services\Recorders\OrderSignalRecorder;
use AIArmada\Signals\Services\Recorders\SignalRecorderSupport;
use AIArmada\Signals\Services\Recorders\VoucherSignalRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Public dispatcher for commerce integrations.
 *
 * The event-specific field mapping lives in source recorders. These methods
 * remain the stable integration surface used by optional package listeners.
 */
final class CommerceSignalsRecorder
{
    private readonly CheckoutSignalRecorder $checkout;

    private readonly OrderSignalRecorder $orders;

    private readonly CartSignalRecorder $cart;

    private readonly FilamentCartSignalRecorder $filamentCart;

    private readonly VoucherSignalRecorder $vouchers;

    private readonly AffiliateSignalRecorder $affiliates;

    private readonly AffiliateNetworkSignalRecorder $affiliateNetwork;

    private readonly SignalRecorderSupport $support;

    public function __construct(TrackedPropertyResolver $trackedPropertyResolver, IngestSignalEvent $ingestSignalEvent)
    {
        $this->support = new SignalRecorderSupport($trackedPropertyResolver, $ingestSignalEvent);
        $this->checkout = new CheckoutSignalRecorder($this->support);
        $this->orders = new OrderSignalRecorder($this->support);
        $this->cart = new CartSignalRecorder($this->support);
        $this->filamentCart = new FilamentCartSignalRecorder($this->support);
        $this->vouchers = new VoucherSignalRecorder($this->support);
        $this->affiliates = new AffiliateSignalRecorder($this->support);
        $this->affiliateNetwork = new AffiliateNetworkSignalRecorder($this->support);
    }

    public function recordCheckoutCompleted(Model $session): ?SignalEvent
    {
        return $this->checkout->recordCompleted($session);
    }

    public function recordCheckoutStarted(Model $session): ?SignalEvent
    {
        return $this->checkout->recordStarted($session);
    }

    public function recordOrderPaid(Model $order, ?string $transactionId = null, ?string $gateway = null): ?SignalEvent
    {
        return $this->orders->recordPaid($order, $transactionId, $gateway);
    }

    public function recordOrderRefunded(Model $order, int $amount, ?string $reason = null): ?SignalEvent
    {
        return $this->orders->recordRefunded($order, $amount, $reason);
    }

    public function recordCartItemAdded(object $cart, object $item): ?SignalEvent
    {
        return $this->cart->recordItemAdded($cart, $item);
    }

    public function recordCartItemRemoved(object $cart, object $item): ?SignalEvent
    {
        return $this->cart->recordItemRemoved($cart, $item);
    }

    public function recordCartCleared(object $cart): ?SignalEvent
    {
        return $this->cart->recordCleared($cart);
    }

    public function recordCartSnapshotSynced(object $event): ?SignalEvent
    {
        return $this->filamentCart->record(
            $event,
            (string) config('signals.integrations.filament_cart.snapshot_synced_event_name', 'cart.snapshot.synced'),
        );
    }

    public function recordCartCheckoutStarted(object $event): ?SignalEvent
    {
        return $this->filamentCart->record(
            $event,
            (string) config('signals.integrations.filament_cart.checkout_started_event_name', 'cart.checkout.started'),
        );
    }

    public function recordCartAbandoned(object $event): ?SignalEvent
    {
        return $this->filamentCart->record(
            $event,
            (string) config('signals.integrations.filament_cart.abandoned_event_name', 'cart.abandoned'),
        );
    }

    public function recordHighValueCartDetected(object $event): ?SignalEvent
    {
        return $this->filamentCart->record(
            $event,
            (string) config('signals.integrations.filament_cart.high_value_detected_event_name', 'cart.high_value.detected'),
        );
    }

    public function recordVoucherApplied(object $cart, object $voucher): ?SignalEvent
    {
        return $this->vouchers->recordApplied($cart, $voucher);
    }

    public function recordVoucherRemoved(object $cart, object $voucher): ?SignalEvent
    {
        return $this->vouchers->recordRemoved($cart, $voucher);
    }

    public function recordAffiliateAttributed(object $attribution): ?SignalEvent
    {
        return $this->affiliates->recordAttributed($attribution);
    }

    public function recordAffiliateConversionRecorded(object $conversion): ?SignalEvent
    {
        return $this->affiliates->recordConversion($conversion);
    }

    public function recordOfferCreated(object $offer): ?SignalEvent
    {
        return $this->affiliateNetwork->recordOfferCreated($offer);
    }

    public function recordOfferUpdated(object $offer): ?SignalEvent
    {
        return $this->affiliateNetwork->recordOfferUpdated($offer);
    }

    public function recordApplicationSubmitted(object $application): ?SignalEvent
    {
        return $this->affiliateNetwork->recordApplicationSubmitted($application);
    }

    public function recordApplicationApproved(object $application): ?SignalEvent
    {
        return $this->affiliateNetwork->recordApplicationApproved($application);
    }

    public function recordNetworkConversionRecorded(object $link, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->affiliateNetwork->recordNetworkConversion($link, $revenueMinor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordSignal(string $eventName, array $data = []): ?SignalEvent
    {
        $trackedProperty = $this->support->resolveTrackedPropertyForOwnerReference(
            $data['owner_type'] ?? null,
            $data['owner_id'] ?? null,
        );

        if ($trackedProperty === null) {
            return null;
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => $data['event_category'] ?? 'commerce',
            'occurred_at' => $this->support->timestampValue($data['occurred_at'] ?? CarbonImmutable::now()),
            'revenue_minor' => (int) ($data['revenue_minor'] ?? 0),
            'currency' => (string) ($data['currency'] ?? config('signals.defaults.currency', 'MYR')),
            'external_id' => $data['external_id'] ?? null,
            'anonymous_id' => $data['anonymous_id'] ?? null,
            'properties' => $data['properties'] ?? array_filter(
                $data,
                static fn (mixed $value, string | int $key): bool => ! in_array($key, [
                    'event_category',
                    'occurred_at',
                    'revenue_minor',
                    'currency',
                    'external_id',
                    'anonymous_id',
                    'owner_type',
                    'owner_id',
                    'properties',
                ], true),
                ARRAY_FILTER_USE_BOTH,
            ),
        ]);
    }
}
