<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

use AIArmada\Signals\Data\GeocodingResult;

interface ReverseGeocoderContract
{
    /**
     * Reverse-geocode a coordinate pair into structured address data.
     *
     * Implementations MUST be idempotent and MUST NOT throw on soft failures
     * (network error, rate-limit, empty result). Return null on any failure.
     *
     * @param  float  $latitude  Decimal degrees, -90..90
     * @param  float  $longitude  Decimal degrees, -180..180
     */
    public function reverseGeocode(float $latitude, float $longitude): ?GeocodingResult;

    /**
     * Human-readable identifier for this provider (e.g. "nominatim", "google").
     * Stored in `signal_sessions.reverse_geocode_provider`.
     */
    public function getProviderName(): string;
}
