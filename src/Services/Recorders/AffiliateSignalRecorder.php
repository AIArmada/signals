<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AffiliateSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordAttributed(object $attribution): ?SignalEvent
    {
        $attributionModel = $this->support->resolveAffiliateModel(
            'AIArmada\\Affiliates\\Models\\AffiliateAttribution',
            $this->support->requiredPublicScalar($attribution, 'id'),
            $this->support->requiredPublicScalar($attribution, 'affiliateId'),
            $this->support->requiredPublicScalar($attribution, 'affiliateCode'),
            $this->support->optionalPublicScalar($attribution, 'ownerType'),
            $this->support->optionalPublicScalar($attribution, 'ownerId'),
        );

        if (! $attributionModel instanceof Model) {
            return null;
        }

        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateModel($attributionModel);

        if ($trackedProperty === null) {
            return null;
        }

        $subjectKey = $this->support->stringValue($attributionModel->getAttribute('subject_key'))
            ?? $this->support->optionalPublicScalar($attribution, 'subjectKey');
        $subjectInstance = $this->support->stringValue($attributionModel->getAttribute('subject_instance'))
            ?? $this->support->optionalPublicScalar($attribution, 'subjectInstance');
        $cartIdentifier = $this->support->stringValue($attributionModel->getAttribute('cart_identifier'))
            ?? $this->support->optionalPublicScalar($attribution, 'cartIdentifier')
            ?? $subjectKey
            ?? $this->support->stringValue($attributionModel->getAttribute('cookie_value'))
            ?? $this->support->optionalPublicScalar($attribution, 'cookieValue');
        $cartInstance = $this->support->stringValue($attributionModel->getAttribute('cart_instance'))
            ?? $this->support->optionalPublicScalar($attribution, 'cartInstance')
            ?? $subjectInstance
            ?? 'default';
        $landingUrl = $this->support->stringValue($attributionModel->getAttribute('landing_url'));
        $referrerUrl = $this->support->stringValue($attributionModel->getAttribute('referrer_url'));

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.attributed_event_name', 'affiliate.attributed'),
            'event_category' => (string) config('signals.integrations.affiliates.attributed_event_category', 'acquisition'),
            'external_id' => $this->support->stringValue($attributionModel->getAttribute('user_id')),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->support->buildAffiliateSessionIdentifier($cartIdentifier, $cartInstance),
            'occurred_at' => $this->support->requiredModelTimestamp($attributionModel, ['last_seen_at', 'created_at']),
            'path' => $landingUrl,
            'url' => $landingUrl,
            'referrer' => $referrerUrl,
            'source' => $this->support->stringValue($attributionModel->getAttribute('source')),
            'medium' => $this->support->stringValue($attributionModel->getAttribute('medium')),
            'campaign' => $this->support->stringValue($attributionModel->getAttribute('campaign')),
            'content' => $this->support->stringValue($attributionModel->getAttribute('content')),
            'term' => $this->support->stringValue($attributionModel->getAttribute('term')),
            'revenue_minor' => 0,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'attribution_id' => $this->support->stringValue($attributionModel->getKey()),
                'affiliate_id' => $this->support->stringValue($attributionModel->getAttribute('affiliate_id'))
                    ?? $this->support->optionalPublicScalar($attribution, 'affiliateId'),
                'affiliate_code' => $this->support->stringValue($attributionModel->getAttribute('affiliate_code'))
                    ?? $this->support->optionalPublicScalar($attribution, 'affiliateCode'),
                'subject_key' => $subjectKey,
                'subject_instance' => $subjectInstance,
                'cart_identifier' => $this->support->stringValue($attributionModel->getAttribute('cart_identifier')),
                'cart_instance' => $this->support->stringValue($attributionModel->getAttribute('cart_instance')),
                'cookie_value' => $this->support->stringValue($attributionModel->getAttribute('cookie_value')),
                'voucher_code' => $this->support->stringValue($attributionModel->getAttribute('voucher_code')),
                'landing_url' => $landingUrl,
                'referrer_url' => $referrerUrl,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordConversion(object $conversion): ?SignalEvent
    {
        $conversionModel = $this->support->resolveAffiliateModel(
            'AIArmada\\Affiliates\\Models\\AffiliateConversion',
            $this->support->requiredPublicScalar($conversion, 'id'),
            $this->support->requiredPublicScalar($conversion, 'affiliateId'),
            $this->support->requiredPublicScalar($conversion, 'affiliateCode'),
            $this->support->optionalPublicScalar($conversion, 'ownerType'),
            $this->support->optionalPublicScalar($conversion, 'ownerId'),
        );

        if (! $conversionModel instanceof Model) {
            return null;
        }

        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateModel($conversionModel);

        if ($trackedProperty === null) {
            return null;
        }

        $attributionModel = $this->support->resolveAffiliateModel(
            'AIArmada\\Affiliates\\Models\\AffiliateAttribution',
            $this->support->stringValue($conversionModel->getAttribute('affiliate_attribution_id')),
            $this->support->stringValue($conversionModel->getAttribute('affiliate_id')),
            $this->support->stringValue($conversionModel->getAttribute('affiliate_code')),
            $this->support->stringValue($conversionModel->getAttribute('owner_type')),
            $this->support->stringValue($conversionModel->getAttribute('owner_id')),
        );
        $subjectKey = $this->support->stringValue($conversionModel->getAttribute('subject_key'))
            ?? $this->support->optionalPublicScalar($conversion, 'subjectKey');
        $subjectInstance = $this->support->stringValue($conversionModel->getAttribute('subject_instance'))
            ?? $this->support->optionalPublicScalar($conversion, 'subjectInstance');
        $revenueMinor = $this->revenueMinor($conversionModel);

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.conversion_event_name', 'affiliate.conversion.recorded'),
            'event_category' => (string) config('signals.integrations.affiliates.conversion_event_category', 'conversion'),
            'external_id' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('user_id')) : null,
            'anonymous_id' => $subjectKey,
            'session_identifier' => $this->support->buildAffiliateSessionIdentifier($subjectKey, $subjectInstance ?? 'default'),
            'occurred_at' => $this->support->requiredModelTimestamp($conversionModel, ['occurred_at', 'created_at']),
            'idempotency_key' => 'affiliate-conversion:' . $conversionModel->getKey(),
            'source_event_id' => (string) $conversionModel->getKey(),
            'path' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('landing_url')) : null,
            'url' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('landing_url')) : null,
            'referrer' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('referrer_url')) : null,
            'source' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('source')) : null,
            'medium' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('medium')) : null,
            'campaign' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('campaign')) : null,
            'content' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('content')) : null,
            'term' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('term')) : null,
            'revenue_minor' => 0,
            'currency' => $this->support->stringValue($conversionModel->getAttribute('commission_currency'))
                ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'conversion_id' => $this->support->stringValue($conversionModel->getKey()),
                'affiliate_id' => $this->support->stringValue($conversionModel->getAttribute('affiliate_id'))
                    ?? $this->support->optionalPublicScalar($conversion, 'affiliateId'),
                'affiliate_code' => $this->support->stringValue($conversionModel->getAttribute('affiliate_code'))
                    ?? $this->support->optionalPublicScalar($conversion, 'affiliateCode'),
                'attribution_id' => $this->support->stringValue($conversionModel->getAttribute('affiliate_attribution_id')),
                'subject_key' => $subjectKey,
                'subject_instance' => $subjectInstance,
                'voucher_code' => $this->support->stringValue($conversionModel->getAttribute('voucher_code')),
                'external_reference' => $this->support->stringValue($conversionModel->getAttribute('external_reference'))
                    ?? $this->support->optionalPublicScalar($conversion, 'externalReference'),
                'order_reference' => $this->support->stringValue($conversionModel->getAttribute('order_reference')),
                'conversion_type' => $this->support->stringValue($conversionModel->getAttribute('conversion_type'))
                    ?? $this->support->optionalPublicScalar($conversion, 'conversionType'),
                'subtotal_minor' => $conversionModel->getAttribute('subtotal_minor'),
                'value_minor' => $revenueMinor,
                'total_minor' => $conversionModel->getAttribute('total_minor'),
                'commission_minor' => $conversionModel->getAttribute('commission_minor'),
                'status' => $this->support->normalizeStateValue($conversionModel->getAttribute('status')),
                'channel' => $this->support->stringValue($conversionModel->getAttribute('channel')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordCreated(Model $affiliate): ?SignalEvent
    {
        $this->assertSourceModel($affiliate, 'AIArmada\\Affiliates\\Models\\Affiliate');

        $canonical = $this->support->resolveSourceModel(
            'AIArmada\\Affiliates\\Models\\Affiliate',
            $this->support->stringValue($affiliate->getKey()),
        );

        if (! $canonical instanceof Model) {
            return null;
        }

        $this->assertCanonicalOwner($affiliate, $canonical, 'affiliate');

        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateModel($canonical);

        if ($trackedProperty === null) {
            return null;
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.created_event_name', 'affiliate.created'),
            'event_category' => (string) config('signals.integrations.affiliates.lifecycle_event_category', 'affiliate_lifecycle'),
            'occurred_at' => $this->support->requiredModelTimestamp($canonical, ['created_at']),
            'idempotency_key' => 'affiliate-created:' . $canonical->getKey(),
            'source_event_id' => (string) $canonical->getKey(),
            'revenue_minor' => 0,
            'currency' => $trackedProperty->currency,
            'properties' => array_filter([
                'affiliate_id' => (string) $canonical->getKey(),
                'affiliate_code' => $this->requiredStringAttribute($canonical, 'code'),
                'registration_approval_mode' => $this->support->stringValue($this->support->modelAttribute($canonical, 'registration_approval_mode')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordProgramJoined(Model $affiliate, Model $program, Model $membership): ?SignalEvent
    {
        $this->assertSourceModel($affiliate, 'AIArmada\\Affiliates\\Models\\Affiliate');
        $this->assertSourceModel($program, 'AIArmada\\Affiliates\\Models\\AffiliateProgram');
        $this->assertSourceModel($membership, 'AIArmada\\Affiliates\\Models\\AffiliateProgramMembership');

        $canonicalMembership = $this->support->resolveSourceModel(
            'AIArmada\\Affiliates\\Models\\AffiliateProgramMembership',
            $this->support->stringValue($membership->getKey()),
        );

        if (! $canonicalMembership instanceof Model) {
            return null;
        }

        $canonicalAffiliate = $this->support->resolveSourceModel(
            'AIArmada\\Affiliates\\Models\\Affiliate',
            $this->support->stringValue($affiliate->getKey()),
        );

        if (! $canonicalAffiliate instanceof Model) {
            return null;
        }

        $canonicalProgram = $this->support->resolveSourceModel(
            'AIArmada\\Affiliates\\Models\\AffiliateProgram',
            $this->support->stringValue($program->getKey()),
        );

        if (! $canonicalProgram instanceof Model) {
            return null;
        }

        $this->assertCanonicalReference(
            $this->support->stringValue($canonicalMembership->getAttribute('affiliate_id')),
            $this->support->stringValue($canonicalAffiliate->getKey()),
            'membership affiliate_id',
        );
        $this->assertCanonicalReference(
            $this->support->stringValue($canonicalMembership->getAttribute('program_id')),
            $this->support->stringValue($canonicalProgram->getKey()),
            'membership program_id',
        );
        $this->assertCanonicalReference(
            $this->support->stringValue($membership->getAttribute('affiliate_id')),
            $this->support->stringValue($canonicalMembership->getAttribute('affiliate_id')),
            'supplied membership affiliate_id',
        );
        $this->assertCanonicalReference(
            $this->support->stringValue($membership->getAttribute('program_id')),
            $this->support->stringValue($canonicalMembership->getAttribute('program_id')),
            'supplied membership program_id',
        );
        $this->assertCanonicalReference(
            $this->support->stringValue($membership->getAttribute('tier_id')),
            $this->support->stringValue($canonicalMembership->getAttribute('tier_id')),
            'supplied membership tier_id',
        );
        $this->assertCanonicalOwner($affiliate, $canonicalAffiliate, 'affiliate');
        $this->assertCanonicalOwner($program, $canonicalProgram, 'program');

        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateModel($canonicalAffiliate);

        if ($trackedProperty === null) {
            return null;
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.program_joined_event_name', 'affiliate.program.joined'),
            'event_category' => (string) config('signals.integrations.affiliates.lifecycle_event_category', 'affiliate_lifecycle'),
            'occurred_at' => $this->support->requiredModelTimestamp($canonicalMembership, ['approved_at']),
            'idempotency_key' => 'affiliate-program-joined:' . $canonicalMembership->getKey(),
            'source_event_id' => (string) $canonicalMembership->getKey(),
            'revenue_minor' => 0,
            'currency' => $trackedProperty->currency,
            'properties' => array_filter([
                'membership_id' => (string) $canonicalMembership->getKey(),
                'affiliate_id' => (string) $canonicalAffiliate->getKey(),
                'affiliate_code' => $this->requiredStringAttribute($canonicalAffiliate, 'code'),
                'program_id' => $this->support->stringValue($canonicalMembership->getAttribute('program_id')),
                'tier_id' => $this->support->stringValue($canonicalMembership->getAttribute('tier_id')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function recordFraudSignalDetected(Model $signal): ?SignalEvent
    {
        $this->assertSourceModel($signal, 'AIArmada\\Affiliates\\Models\\AffiliateFraudSignal');

        $canonical = $this->support->resolveSourceModel(
            'AIArmada\\Affiliates\\Models\\AffiliateFraudSignal',
            $this->support->stringValue($signal->getKey()),
        );

        if (! $canonical instanceof Model) {
            return null;
        }

        $this->assertCanonicalReference(
            $this->support->stringValue($signal->getAttribute('affiliate_id')),
            $this->support->stringValue($canonical->getAttribute('affiliate_id')),
            'supplied fraud signal affiliate_id',
        );
        $this->assertCanonicalReference(
            $this->support->stringValue($signal->getAttribute('conversion_id')),
            $this->support->stringValue($canonical->getAttribute('conversion_id')),
            'supplied fraud signal conversion_id',
        );
        $this->assertCanonicalReference(
            $this->support->stringValue($signal->getAttribute('touchpoint_id')),
            $this->support->stringValue($canonical->getAttribute('touchpoint_id')),
            'supplied fraud signal touchpoint_id',
        );

        $affiliate = $this->support->resolveSourceModel(
            'AIArmada\\Affiliates\\Models\\Affiliate',
            $this->support->stringValue($canonical->getAttribute('affiliate_id')),
        );

        if (! $affiliate instanceof Model) {
            return null;
        }

        $trackedProperty = $this->support->resolveTrackedPropertyForAffiliateModel($affiliate);

        if ($trackedProperty === null) {
            return null;
        }

        $severity = $canonical->getAttribute('severity');

        return $this->support->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.fraud_detected_event_name', 'affiliate.fraud.detected'),
            'event_category' => (string) config('signals.integrations.affiliates.fraud_event_category', 'affiliate_risk'),
            'occurred_at' => $this->support->requiredModelTimestamp($canonical, ['detected_at']),
            'idempotency_key' => 'affiliate-fraud-detected:' . $canonical->getKey(),
            'source_event_id' => (string) $canonical->getKey(),
            'revenue_minor' => 0,
            'currency' => $trackedProperty->currency,
            'properties' => array_filter([
                'fraud_signal_id' => (string) $canonical->getKey(),
                'affiliate_id' => (string) $affiliate->getKey(),
                'conversion_id' => $this->support->stringValue($canonical->getAttribute('conversion_id')),
                'touchpoint_id' => $this->support->stringValue($canonical->getAttribute('touchpoint_id')),
                'rule_code' => $this->requiredStringAttribute($canonical, 'rule_code'),
                'severity' => $severity instanceof BackedEnum ? $severity->value : $this->support->stringValue($severity),
                'risk_points' => $this->support->requiredModelInt($canonical, 'risk_points'),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function revenueMinor(Model $conversion): int
    {
        $attributes = $conversion->getAttributes();

        if (! array_key_exists('value_minor', $attributes) && ! array_key_exists('total_minor', $attributes)) {
            throw new InvalidArgumentException('Trusted affiliate conversion is missing value_minor and total_minor.');
        }

        $valueMinor = array_key_exists('value_minor', $attributes)
            ? $conversion->getRawOriginal('value_minor')
            : null;

        if ($valueMinor !== null && ! is_numeric($valueMinor)) {
            throw new InvalidArgumentException('Trusted affiliate conversion value_minor must be numeric.');
        }

        if ((int) $valueMinor !== 0 || ! array_key_exists('total_minor', $attributes)) {
            if ($valueMinor === null) {
                throw new InvalidArgumentException('Trusted affiliate conversion is missing value_minor.');
            }

            return (int) $valueMinor;
        }

        $totalMinor = $conversion->getRawOriginal('total_minor');

        if ($totalMinor === null || ! is_numeric($totalMinor)) {
            throw new InvalidArgumentException('Trusted affiliate conversion total_minor must be numeric.');
        }

        return (int) $totalMinor;
    }

    private function assertSourceModel(Model $model, string $expectedClass): void
    {
        if (! $model instanceof $expectedClass) {
            throw new InvalidArgumentException(sprintf(
                'Trusted signal source must be an instance of [%s], [%s] given.',
                $expectedClass,
                $model::class,
            ));
        }
    }

    private function requiredStringAttribute(Model $model, string $attribute): string
    {
        $value = $this->support->stringValue($this->support->modelAttribute($model, $attribute));

        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('Trusted signal source is missing string attribute [%s].', $attribute));
        }

        return $value;
    }

    private function assertCanonicalReference(?string $supplied, ?string $canonical, string $what): void
    {
        if ($supplied !== $canonical) {
            throw new InvalidArgumentException(sprintf('Trusted affiliate %s does not match the canonical source.', $what));
        }
    }

    private function assertCanonicalOwner(Model $supplied, Model $canonical, string $what): void
    {
        $suppliedType = $this->support->stringValue($supplied->getAttribute('owner_type'));
        $suppliedId = $this->support->stringValue($supplied->getAttribute('owner_id'));
        $canonicalType = $this->support->stringValue($canonical->getAttribute('owner_type'));
        $canonicalId = $this->support->stringValue($canonical->getAttribute('owner_id'));

        if ($suppliedType !== $canonicalType || $suppliedId !== $canonicalId) {
            throw new InvalidArgumentException(sprintf('Trusted affiliate %s owner does not match the canonical source.', $what));
        }
    }
}
