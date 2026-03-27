<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Geocoders;

use AIArmada\Signals\Contracts\ReverseGeocoderContract;
use AIArmada\Signals\Data\GeocodingResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class NominatimGeocoder implements ReverseGeocoderContract
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    public function reverseGeocode(float $latitude, float $longitude): ?GeocodingResult
    {
        try {
            $appName = config('app.name', 'Signals');
            $userAgent = "{$appName} signals-package/1.0";

            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(5)
                ->get(self::ENDPOINT, [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'format' => 'json',
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $data = $response->json();

            /** @var array<string, mixed> $address */
            $address = $data['address'] ?? [];

            $displayName = is_string($data['display_name'] ?? null) ? (string) $data['display_name'] : '';

            return GeocodingResult::fromNominatim($address, $displayName, $data);
        } catch (Throwable) {
            return null;
        }
    }

    public function getProviderName(): string
    {
        return 'nominatim';
    }
}
