<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Contracts\ReverseGeocoderContract;
use AIArmada\Signals\Contracts\SignalLocationResolverContract;
use AIArmada\Signals\Data\GeocodingResult;
use AIArmada\Signals\Models\SignalSession;
use Carbon\CarbonImmutable;

class SignalLocationResolverPipeline
{
    /** @var ReverseGeocoderContract[] */
    private array $geocoders = [];

    /** @var SignalLocationResolverContract[] */
    private array $resolvers = [];

    public function registerGeocoder(ReverseGeocoderContract $geocoder): void
    {
        $this->geocoders[] = $geocoder;
    }

    public function registerResolver(SignalLocationResolverContract $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * Run the full location enrichment pipeline on a session.
     *
     * Steps:
     *  1. Try each registered geocoder in order; stop on first success.
     *  2. Persist standard geocoding fields onto the session.
     *  3. Call each registered resolver for app-specific enrichment.
     */
    public function run(SignalSession $session): void
    {
        if ($session->latitude === null || $session->longitude === null) {
            return;
        }

        if ($session->reverse_geocoded_at !== null) {
            return;
        }

        $geocoderResult = $this->runGeocoders((float) $session->latitude, (float) $session->longitude);

        if ($geocoderResult === null) {
            return;
        }

        [$result, $providerName] = $geocoderResult;

        $storeRawPayload = (bool) config('signals.features.geolocation.reverse_geocode.store_raw_payload', false);

        $session->update([
            'resolved_country_code' => $result->countryCode,
            'resolved_country_name' => $result->countryName,
            'resolved_state' => $result->state,
            'resolved_city' => $result->city,
            'resolved_postcode' => $result->postcode,
            'resolved_formatted_address' => $result->formattedAddress,
            'raw_reverse_geocode_payload' => $storeRawPayload ? $result->rawPayload : null,
            'reverse_geocode_provider' => $providerName,
            'reverse_geocoded_at' => CarbonImmutable::now(),
        ]);

        foreach ($this->resolvers as $resolver) {
            $resolver->resolve($session->refresh(), $result->rawPayload);
        }
    }

    /**
     * @return array{GeocodingResult, string}|null [result, providerName] or null if all geocoders fail
     */
    private function runGeocoders(float $latitude, float $longitude): ?array
    {
        foreach ($this->geocoders as $geocoder) {
            $result = $geocoder->reverseGeocode($latitude, $longitude);
            if ($result !== null) {
                return [$result, $geocoder->getProviderName()];
            }
        }

        return null;
    }
}
