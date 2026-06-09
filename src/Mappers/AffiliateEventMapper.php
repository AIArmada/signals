<?php

declare(strict_types=1);

namespace AIArmada\Signals\Mappers;

use AIArmada\Signals\Contracts\MapCommerceEventToSignalInterface;

final class AffiliateEventMapper implements MapCommerceEventToSignalInterface
{
    public function map(object $event): ?array
    {
        $affiliate = $event->affiliate ?? $event->conversion ?? $event->offer ?? null;

        if (! is_object($affiliate)) {
            return null;
        }

        $eventClass = $event::class;

        if (str_contains($eventClass, 'AffiliateAttributed')) {
            return [
                'event_type' => 'affiliate_attributed',
                'data' => [
                    'affiliate_id' => method_exists($affiliate, 'getKey') ? $affiliate->getKey() : null,
                ],
            ];
        }

        if (str_contains($eventClass, 'AffiliateConversionRecorded')) {
            return [
                'event_type' => 'affiliate_conversion',
                'data' => [
                    'affiliate_id' => method_exists($affiliate, 'getKey') ? $affiliate->getKey() : null,
                ],
            ];
        }

        if (str_contains($eventClass, 'OfferCreated')) {
            return [
                'event_type' => 'affiliate_offer_created',
                'data' => [
                    'offer_id' => method_exists($affiliate, 'getKey') ? $affiliate->getKey() : null,
                ],
            ];
        }

        if (str_contains($eventClass, 'OfferUpdated')) {
            return [
                'event_type' => 'affiliate_offer_updated',
                'data' => [
                    'offer_id' => method_exists($affiliate, 'getKey') ? $affiliate->getKey() : null,
                ],
            ];
        }

        if (str_contains($eventClass, 'ApplicationSubmitted')) {
            return [
                'event_type' => 'affiliate_application_submitted',
                'data' => [],
            ];
        }

        if (str_contains($eventClass, 'ApplicationApproved')) {
            return [
                'event_type' => 'affiliate_application_approved',
                'data' => [],
            ];
        }

        if (str_contains($eventClass, 'NetworkConversionRecorded')) {
            return [
                'event_type' => 'affiliate_network_conversion',
                'data' => [],
            ];
        }

        return null;
    }

    public function handles(): string
    {
        return 'AIArmada\\Affiliates\\Events\\AffiliateAttributed';
    }

    /**
     * @return array<class-string>
     */
    public static function handledEvents(): array
    {
        return [
            'AIArmada\\Affiliates\\Events\\AffiliateAttributed',
            'AIArmada\\Affiliates\\Events\\AffiliateConversionRecorded',
            'AIArmada\\AffiliateNetwork\\Events\\OfferCreated',
            'AIArmada\\AffiliateNetwork\\Events\\OfferUpdated',
            'AIArmada\\AffiliateNetwork\\Events\\ApplicationSubmitted',
            'AIArmada\\AffiliateNetwork\\Events\\ApplicationApproved',
            'AIArmada\\AffiliateNetwork\\Events\\NetworkConversionRecorded',
        ];
    }
}
