<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

/**
 * Single normalization point for Signals currency codes.
 *
 * Currency codes are stored uppercase and compared case-insensitively.
 * Missing or blank values fall back to the configured default currency.
 */
final class SignalCurrency
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtoupper(mb_trim($value));

        return $normalized === '' ? null : $normalized;
    }

    public static function code(mixed $value): string
    {
        return self::normalize($value) ?? self::default();
    }

    public static function default(): string
    {
        return self::normalize((string) config('signals.defaults.currency', 'MYR')) ?? 'MYR';
    }

    public static function matches(mixed $candidate, mixed $expected): bool
    {
        $normalizedCandidate = self::normalize($candidate);
        $normalizedExpected = self::normalize($expected);

        return $normalizedCandidate !== null
            && $normalizedExpected !== null
            && $normalizedCandidate === $normalizedExpected;
    }
}
