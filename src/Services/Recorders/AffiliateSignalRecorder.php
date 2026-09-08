<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;
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
            'path' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('landing_url')) : null,
            'url' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('landing_url')) : null,
            'referrer' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('referrer_url')) : null,
            'source' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('source')) : null,
            'medium' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('medium')) : null,
            'campaign' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('campaign')) : null,
            'content' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('content')) : null,
            'term' => $attributionModel instanceof Model ? $this->support->stringValue($attributionModel->getAttribute('term')) : null,
            'revenue_minor' => $revenueMinor,
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
}
