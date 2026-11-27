<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\Affiliates\Features\Fraud\Api\FraudSignalReference;
use AIArmada\Affiliates\Features\Identity\Api\AffiliateLifecycleReference;
use AIArmada\Affiliates\Features\Programs\Api\ProgramJoinReference;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\AffiliateNetworkSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\AffiliateSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\CartSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\CheckoutSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\FilamentCartSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\LinkSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\OrderSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\SignalRecorderSupport;
use AIArmada\Signals\Features\Ingestion\Actions\Recorders\VoucherSignalRecorder;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
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

    private readonly LinkSignalRecorder $links;

    private readonly SignalRecorderSupport $support;

    public function __construct(ResolveTrackedProperty $trackedPropertyResolver, IngestSignalEvent $ingestSignalEvent)
    {
        $this->support = new SignalRecorderSupport($trackedPropertyResolver, $ingestSignalEvent);
        $this->checkout = new CheckoutSignalRecorder($this->support);
        $this->orders = new OrderSignalRecorder($this->support);
        $this->cart = new CartSignalRecorder($this->support);
        $this->filamentCart = new FilamentCartSignalRecorder($this->support);
        $this->vouchers = new VoucherSignalRecorder($this->support);
        $this->affiliates = new AffiliateSignalRecorder($this->support);
        $this->affiliateNetwork = new AffiliateNetworkSignalRecorder($this->support);
        $this->links = new LinkSignalRecorder($this->support);
    }

    public function recordCheckoutCompleted(Model $session): ?SignalEvent
    {
        return $this->checkout->recordCompleted($session);
    }

    public function recordCheckoutStarted(Model $session): ?SignalEvent
    {
        return $this->checkout->recordStarted($session);
    }

    public function recordOrderPaid(Model $order, string $transactionId, string $gateway, int $amount): ?SignalEvent
    {
        return $this->orders->recordPaid($order, $transactionId, $gateway, $amount);
    }

    public function recordOrderRefunded(Model $order, string $refundId, int $amount, ?string $reason = null): ?SignalEvent
    {
        return $this->orders->recordRefunded($order, $refundId, $amount, $reason);
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

    public function recordAffiliateCreated(AffiliateLifecycleReference $affiliate): ?SignalEvent
    {
        return $this->affiliates->recordCreated($affiliate);
    }

    public function recordAffiliateProgramJoined(ProgramJoinReference $reference): ?SignalEvent
    {
        return $this->affiliates->recordProgramJoined($reference);
    }

    public function recordAffiliateFraudSignalDetected(FraudSignalReference $signal): ?SignalEvent
    {
        return $this->affiliates->recordFraudSignalDetected($signal);
    }

    public function recordOfferCreated(object $event): ?SignalEvent
    {
        return $this->affiliateNetwork->recordOfferCreated($event);
    }

    public function recordOfferUpdated(object $event): ?SignalEvent
    {
        return $this->affiliateNetwork->recordOfferUpdated($event);
    }

    public function recordApplicationSubmitted(object $event): ?SignalEvent
    {
        return $this->affiliateNetwork->recordApplicationSubmitted($event);
    }

    public function recordApplicationApproved(object $event): ?SignalEvent
    {
        return $this->affiliateNetwork->recordApplicationApproved($event);
    }

    public function recordNetworkConversionRecorded(object $event): ?SignalEvent
    {
        return $this->affiliateNetwork->recordNetworkConversion($event);
    }

    public function recordLinkClicked(Model $link, Model $click): ?SignalEvent
    {
        return $this->links->recordClicked($link, $click);
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
