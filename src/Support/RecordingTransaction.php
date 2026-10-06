<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\Signals\Exceptions\CommerceSignalTransactionControlFailed;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs recording-owned transactions with transaction-control provenance.
 *
 * Laravel rethrows a callback failure unchanged when its own rollback
 * succeeds, but begin, savepoint, commit, and rollback machinery failures
 * escape as a different object that masks the callback failure. Tracking
 * the callback failure by identity tells the two apart: callback failures
 * propagate untouched for duplicate recovery, validation, and exhaustion
 * reporting, while machinery failures are marked so the automatic listener
 * propagates them unreported instead of converting them into recoverable
 * recording failures. Deadlock replacements pass through untouched so the
 * listener can unwrap them for host retry; all other control failures are
 * marked even when recognized, with the recognized original preserved in
 * the marker chain for the host boundary.
 */
final class RecordingTransaction
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback, int $attempts = 1): mixed
    {
        /** @var Throwable|null $callbackFailure */
        $callbackFailure = null;

        try {
            return DB::transaction(function () use ($callback, &$callbackFailure): mixed {
                try {
                    return $callback();
                } catch (Throwable $e) {
                    $callbackFailure = $e;

                    throw $e;
                }
            }, $attempts);
        } catch (Throwable $e) {
            throw self::classifyEscaped($e, $callbackFailure);
        }
    }

    /**
     * Decide what escapes a recording-owned transaction.
     *
     * Pure decision: a DeadlockException passes through so the listener
     * can unwrap Laravel's nested replacement for host retry; identity
     * means the machinery stayed clean, so callback failures — including
     * recognized contention bound for exhaustion reporting and duplicate
     * keys bound for recovery — propagate untouched. Anything else is a
     * transaction-control failure: provenance beats recognition, because
     * matching bookkeeping cannot make an infrastructure failure
     * recoverable. The recognized original is preserved in the marker
     * chain and unwrapped at the host boundary when the host can retry.
     */
    public static function classifyEscaped(Throwable $escaped, ?Throwable $callbackFailure): Throwable
    {
        if ($escaped instanceof DeadlockException) {
            return $escaped;
        }

        if ($callbackFailure !== null && $escaped === $callbackFailure) {
            return $escaped;
        }

        return CommerceSignalTransactionControlFailed::fromControlFailure($escaped, $callbackFailure);
    }
}
