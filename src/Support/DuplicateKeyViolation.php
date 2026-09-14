<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use Illuminate\Database\QueryException;

/**
 * Driver-agnostic unique-violation detection for idempotent retries.
 */
final class DuplicateKeyViolation
{
    public static function is(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === '23505') {
            return true;
        }

        $driverCode = $exception->errorInfo[1] ?? null;

        if ($driverCode === 1062) {
            return true;
        }

        if ($driverCode === 19) {
            return str_contains((string) ($exception->errorInfo[2] ?? ''), 'UNIQUE constraint failed');
        }

        return false;
    }
}
