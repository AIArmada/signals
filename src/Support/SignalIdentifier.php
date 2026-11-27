<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

final class SignalIdentifier
{
    public const int MAX_BYTES = 255;

    public static function normalize(mixed $value, int $maxBytes = self::MAX_BYTES): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        if (! mb_check_encoding($string, 'UTF-8')) {
            return null;
        }

        if (preg_match('/\p{Cc}/u', $string) === 1) {
            return null;
        }

        $normalized = mb_trim($string);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, '8bit') > max(1, $maxBytes)) {
            return null;
        }

        return $normalized;
    }
}
