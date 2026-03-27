<?php

declare(strict_types=1);

namespace AIArmada\Signals\Data;

/**
 * Normalised result from any reverse-geocoding provider.
 *
 * All string fields may be null when the provider does not supply that level
 * of detail for the queried coordinate.
 */
readonly class GeocodingResult
{
    public function __construct(
        /** ISO 3166-1 alpha-2 country code (e.g. "MY") */
        public ?string $countryCode,

        /** Human-readable country name in English */
        public ?string $countryName,

        /** State / province / region */
        public ?string $state,

        /** City / locality */
        public ?string $city,

        /** Postal / postcode */
        public ?string $postcode,

        /** Full human-readable address string */
        public ?string $formattedAddress,

        /**
         * Provider-specific raw response payload.
         *
         * @var array<string, mixed>
         */
        public array $rawPayload = [],
    ) {}

    /**
     * Build from a Nominatim-style address array.
     *
     * @param  array<string, mixed>  $nominatimAddress  The `address` sub-key from a Nominatim response
     * @param  string  $displayName  The top-level `display_name` field
     * @param  array<string, mixed>  $rawPayload  The full Nominatim response for provenance
     */
    public static function fromNominatim(
        array $nominatimAddress,
        string $displayName,
        array $rawPayload,
    ): self {
        return new self(
            countryCode: isset($nominatimAddress['country_code'])
                ? mb_strtoupper((string) $nominatimAddress['country_code'])
                : null,
            countryName: $nominatimAddress['country'] ?? null,
            state: $nominatimAddress['state']
                ?? $nominatimAddress['region']
                ?? $nominatimAddress['province']
                ?? null,
            city: $nominatimAddress['city']
                ?? $nominatimAddress['town']
                ?? $nominatimAddress['municipality']
                ?? $nominatimAddress['village']
                ?? null,
            postcode: $nominatimAddress['postcode'] ?? null,
            formattedAddress: $displayName !== '' ? $displayName : null,
            rawPayload: $rawPayload,
        );
    }
}
