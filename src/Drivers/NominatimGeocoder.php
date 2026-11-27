<?php

declare(strict_types=1);

namespace AIArmada\Signals\Drivers;

use AIArmada\Signals\Contracts\ReverseGeocoderContract;
use AIArmada\Signals\Data\GeocodingResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class NominatimGeocoder implements ReverseGeocoderContract
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    public function reverseGeocode(float $latitude, float $longitude): ?GeocodingResult
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

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

            $data = $response->json();

            if (! is_array($data) || ! is_array($data['address'] ?? null) || $data['address'] === []) {
                return null;
            }

            $address = $data['address'];

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
