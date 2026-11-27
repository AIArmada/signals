<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions\Recorders;

use AIArmada\Affiliates\Data\AffiliateEventReference;
use AIArmada\Affiliates\Features\Attribution\Api\AttributionSignalReader;
use AIArmada\Affiliates\Features\Conversions\Api\ConversionSignalReader;
use AIArmada\Affiliates\Features\Fraud\Api\FraudSignalReader;
use AIArmada\Affiliates\Features\Fraud\Api\FraudSignalReference;
use AIArmada\Affiliates\Features\Identity\Api\AffiliateLifecycleReader;
use AIArmada\Affiliates\Features\Identity\Api\AffiliateLifecycleReference;
use AIArmada\Affiliates\Features\Programs\Api\ProgramJoinReader;
use AIArmada\Affiliates\Features\Programs\Api\ProgramJoinReference;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use InvalidArgumentException;

final class AffiliateSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordAttributed(object $attribution): ?SignalEvent
    {
        $reference = $this->reference($attribution);
        $record = app(AttributionSignalReader::class)->find($reference);

        if ($record === null) {
            return null;
        }

        $reference = $record->reference;
        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateOwner($reference->ownerType, $reference->ownerId);

        if ($trackedProperty === null) {
            return null;
        }

        if ($record->occurredAt === null) {
            throw new InvalidArgumentException('Trusted signal source is missing timestamp attributes [last_seen_at, created_at].');
        }

        $cartIdentifier = $record->cartIdentifier ?? $record->subjectKey ?? $record->cookieValue;

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.attributed_event_name', 'affiliate.attributed'),
            'event_category' => (string) config('signals.integrations.affiliates.attributed_event_category', 'acquisition'),
            'external_id' => $record->userId,
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->support->buildAffiliateSessionIdentifier($cartIdentifier, $record->cartInstance),
            'occurred_at' => $record->occurredAt,
            'path' => $record->landingUrl,
            'url' => $record->landingUrl,
            'referrer' => $record->referrerUrl,
            'source' => $record->source,
            'medium' => $record->medium,
            'campaign' => $record->campaign,
            'content' => $record->content,
            'term' => $record->term,
            'revenue_minor' => 0,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'attribution_id' => $reference->id,
                'affiliate_id' => $reference->affiliateId,
                'affiliate_code' => $reference->affiliateCode,
                'subject_key' => $record->subjectKey,
                'subject_instance' => $record->subjectInstance,
                'cart_identifier' => $record->cartIdentifier,
                'cart_instance' => $record->cartInstance,
                'cookie_value' => $record->cookieValue,
                'voucher_code' => $record->voucherCode,
                'landing_url' => $record->landingUrl,
                'referrer_url' => $record->referrerUrl,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordConversion(object $conversion): ?SignalEvent
    {
        $reference = $this->reference($conversion);
        $record = app(ConversionSignalReader::class)->find($reference);

        if ($record === null) {
            return null;
        }

        $reference = $record->reference;
        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateOwner($reference->ownerType, $reference->ownerId);

        if ($trackedProperty === null) {
            return null;
        }

        if ($record->occurredAt === null) {
            throw new InvalidArgumentException('Trusted signal source is missing timestamp attributes [occurred_at, created_at].');
        }

        $attribution = $record->attributionId === null ? null : app(AttributionSignalReader::class)->find(new AffiliateEventReference(
            id: $record->attributionId,
            affiliateId: $reference->affiliateId,
            affiliateCode: $reference->affiliateCode,
            ownerType: $reference->ownerType,
            ownerId: $reference->ownerId,
        ));

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.conversion_event_name', 'affiliate.conversion.recorded'),
            'event_category' => (string) config('signals.integrations.affiliates.conversion_event_category', 'conversion'),
            'external_id' => $attribution?->userId,
            'anonymous_id' => $record->subjectKey,
            'session_identifier' => $this->support->buildAffiliateSessionIdentifier($record->subjectKey, $record->subjectInstance ?? 'default'),
            'occurred_at' => $record->occurredAt,
            'idempotency_key' => 'affiliate-conversion:' . $reference->id,
            'source_event_id' => $reference->id,
            'path' => $attribution?->landingUrl,
            'url' => $attribution?->landingUrl,
            'referrer' => $attribution?->referrerUrl,
            'source' => $attribution?->source,
            'medium' => $attribution?->medium,
            'campaign' => $attribution?->campaign,
            'content' => $attribution?->content,
            'term' => $attribution?->term,
            'revenue_minor' => 0,
            'currency' => $record->commissionCurrency,
            'properties' => array_filter([
                'conversion_id' => $reference->id,
                'affiliate_id' => $reference->affiliateId,
                'affiliate_code' => $reference->affiliateCode,
                'attribution_id' => $record->attributionId,
                'subject_key' => $record->subjectKey,
                'subject_instance' => $record->subjectInstance,
                'voucher_code' => $record->voucherCode,
                'external_reference' => $record->externalReference,
                'conversion_type' => $record->conversionType,
                'subtotal_minor' => $record->subtotalMinor,
                'value_minor' => $record->valueMinor,
                'commission_minor' => $record->commissionMinor,
                'status' => $record->status,
                'channel' => $record->channel,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordCreated(AffiliateLifecycleReference $affiliate): ?SignalEvent
    {
        $canonical = app(AffiliateLifecycleReader::class)->find($affiliate);

        if ($canonical === null) {
            return null;
        }

        $reference = $canonical->reference;
        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateOwner($reference->ownerType, $reference->ownerId);

        if ($trackedProperty === null) {
            return null;
        }

        if ($canonical->createdAt === null) {
            throw new InvalidArgumentException('Trusted signal source is missing timestamp attributes [created_at].');
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.created_event_name', 'affiliate.created'),
            'event_category' => (string) config('signals.integrations.affiliates.lifecycle_event_category', 'affiliate_lifecycle'),
            'occurred_at' => $canonical->createdAt->toIso8601String(),
            'idempotency_key' => 'affiliate-created:' . $reference->id,
            'source_event_id' => $reference->id,
            'revenue_minor' => 0,
            'currency' => $trackedProperty->currency,
            'properties' => array_filter([
                'affiliate_id' => $reference->id,
                'affiliate_code' => $canonical->code,
                'registration_approval_mode' => $canonical->registrationApprovalMode,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordProgramJoined(ProgramJoinReference $reference): ?SignalEvent
    {
        $canonical = app(ProgramJoinReader::class)->find($reference);

        if ($canonical === null) {
            return null;
        }

        $reference = $canonical->reference;
        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateOwner($reference->affiliate->ownerType, $reference->affiliate->ownerId);

        if ($trackedProperty === null) {
            return null;
        }

        if ($canonical->approvedAt === null) {
            throw new InvalidArgumentException('Trusted signal source is missing timestamp attributes [approved_at].');
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.program_joined_event_name', 'affiliate.program.joined'),
            'event_category' => (string) config('signals.integrations.affiliates.lifecycle_event_category', 'affiliate_lifecycle'),
            'occurred_at' => $canonical->approvedAt,
            'idempotency_key' => 'affiliate-program-joined:' . $reference->membershipId,
            'source_event_id' => $reference->membershipId,
            'revenue_minor' => 0,
            'currency' => $trackedProperty->currency,
            'properties' => array_filter([
                'membership_id' => $reference->membershipId,
                'affiliate_id' => $reference->affiliate->id,
                'affiliate_code' => $canonical->affiliateCode,
                'program_id' => $reference->programId,
                'tier_id' => $reference->tierId,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordFraudSignalDetected(FraudSignalReference $signal): ?SignalEvent
    {
        $canonical = app(FraudSignalReader::class)->findUnscoped($signal->id);

        if ($canonical === null) {
            return null;
        }

        $reference = $canonical->reference;
        $this->assertCanonicalReference($signal->affiliateId, $reference->affiliateId, 'supplied fraud signal affiliate_id');
        $this->assertCanonicalReference($signal->conversionId, $reference->conversionId, 'supplied fraud signal conversion_id');
        $this->assertCanonicalReference($signal->touchpointId, $reference->touchpointId, 'supplied fraud signal touchpoint_id');

        $affiliate = app(AffiliateLifecycleReader::class)->findById($reference->affiliateId);

        if ($affiliate === null) {
            return null;
        }

        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateOwner($affiliate->reference->ownerType, $affiliate->reference->ownerId);

        if ($trackedProperty === null) {
            return null;
        }

        if ($canonical->detectedAt === null) {
            throw new InvalidArgumentException('Trusted signal source is missing timestamp attributes [detected_at].');
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.fraud_detected_event_name', 'affiliate.fraud.detected'),
            'event_category' => (string) config('signals.integrations.affiliates.fraud_event_category', 'affiliate_risk'),
            'occurred_at' => $canonical->detectedAt->toIso8601String(),
            'idempotency_key' => 'affiliate-fraud-detected:' . $reference->id,
            'source_event_id' => $reference->id,
            'revenue_minor' => 0,
            'currency' => $trackedProperty->currency,
            'properties' => array_filter([
                'fraud_signal_id' => $reference->id,
                'affiliate_id' => $affiliate->reference->id,
                'conversion_id' => $reference->conversionId,
                'touchpoint_id' => $reference->touchpointId,
                'rule_code' => $canonical->ruleCode,
                'severity' => $canonical->severity->value,
                'risk_points' => $canonical->riskPoints,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function reference(object $source): AffiliateEventReference
    {
        return new AffiliateEventReference(
            id: $this->support->requiredPublicScalar($source, 'id'),
            affiliateId: $this->support->requiredPublicScalar($source, 'affiliateId'),
            affiliateCode: $this->support->requiredPublicScalar($source, 'affiliateCode'),
            ownerType: $this->support->optionalPublicScalar($source, 'ownerType'),
            ownerId: $this->support->optionalPublicScalar($source, 'ownerId'),
        );
    }

    private function assertCanonicalReference(?string $supplied, ?string $canonical, string $what): void
    {
        if ($supplied !== $canonical) {
            throw new InvalidArgumentException(sprintf('Trusted affiliate %s does not match the canonical source.', $what));
        }
    }
}
