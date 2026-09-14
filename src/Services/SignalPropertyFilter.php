<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

/**
 * Privacy filter shared by event properties and identity traits.
 *
 * The PII blocklist always applies, even when the allowlist contains '*'.
 */
final class SignalPropertyFilter
{
    /** @var list<string> */
    private const BLOCKED_KEYS = [
        'email',
        'phone',
        'name',
        'first_name',
        'last_name',
        'customer_email',
        'customer_phone',
        'customer_name',
        'metadata',
        'cart_metadata',
    ];

    /**
     * @param  array<string, mixed>|null  $properties
     * @return array<string, mixed>|null
     */
    public function filter(?array $properties): ?array
    {
        if ($properties === null) {
            return null;
        }

        $allowlist = config('signals.features.privacy.property_allowlist', []);
        $allowedKeys = is_array($allowlist) ? array_values(array_filter($allowlist, 'is_string')) : [];
        $allowAll = in_array('*', $allowedKeys, true);

        return array_filter(
            $properties,
            static fn (mixed $value, mixed $key): bool => is_string($key)
                && ($allowAll || in_array($key, $allowedKeys, true))
                && ! in_array($key, self::BLOCKED_KEYS, true)
                && (is_scalar($value) || is_array($value) || $value === null),
            ARRAY_FILTER_USE_BOTH,
        ) ?: null;
    }
}
