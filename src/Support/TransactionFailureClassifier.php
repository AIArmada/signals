<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\Signals\Exceptions\CommerceSignalTransactionControlFailed;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionDetector;
use Throwable;

/**
 * Classifies database failures for the automatic commerce listener.
 *
 * Callback-originated concurrency and lost-connection failures must
 * propagate from the outer-transaction branch so enclosing transactions
 * can retry or fail honestly; without an outer transaction the ingestor
 * first exhausts its bounded retries and terminal callback-originated
 * failures are then reported and skipped with other recoverable
 * recording failures. Control-originated failures are marked separately
 * and always propagate unreported. Detection mirrors Laravel's own
 * detector traits, including support for rebound detector contracts.
 */
final class TransactionFailureClassifier
{
    public static function causedByConcurrencyError(Throwable $e): bool
    {
        return self::concurrencyDetector()->causedByConcurrencyError($e);
    }

    public static function causedByLostConnection(Throwable $e): bool
    {
        return self::lostConnectionDetector()->causedByLostConnection($e);
    }

    /**
     * Unwrap a Laravel-generated DeadlockException to the deepest previous
     * throwable the detector still recognizes.
     *
     * Nested transaction handling replaces the original failure with a
     * DeadlockException carrying code 0, which destroys string-SQLSTATE
     * classification (for example PostgreSQL '40001') at the host retry
     * boundary. Unwrapping restores nesting-level transparency: the host
     * sees what it would have seen without the listener's savepoint.
     * Message-recognized and custom deadlocks pass through unchanged.
     */
    public static function unwrapForHostRetry(DeadlockException $e): Throwable
    {
        if (self::causedByConcurrencyError($e)) {
            return $e;
        }

        $recognized = null;
        $candidate = $e->getPrevious();

        while ($candidate !== null) {
            if (self::causedByConcurrencyError($candidate)) {
                $recognized = $candidate;
            }

            $candidate = $candidate->getPrevious();
        }

        return $recognized ?? $e;
    }

    /**
     * Unwrap a transaction-control marker for host retry.
     *
     * Control failures propagate unreported, but when the control failure
     * is recognized contention the host keeps its retry classification:
     * the recognized original crosses the boundary instead of the opaque
     * marker. Deadlock causes unwrap through the nested-replacement path;
     * lost connections and unrecognized machinery failures have no host
     * retry meaning and cross as the marker itself.
     */
    public static function unwrapMarkerForHostRetry(CommerceSignalTransactionControlFailed $e): Throwable
    {
        $cause = $e->getPrevious();

        if ($cause instanceof DeadlockException) {
            return self::unwrapForHostRetry($cause);
        }

        if ($cause !== null && self::causedByConcurrencyError($cause)) {
            return $cause;
        }

        return $e;
    }

    private static function concurrencyDetector(): ConcurrencyErrorDetectorContract
    {
        $container = Container::getInstance();

        $detector = $container->bound(ConcurrencyErrorDetectorContract::class)
            ? $container->make(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector;

        return $detector instanceof ConcurrencyErrorDetectorContract
            ? $detector
            : new ConcurrencyErrorDetector;
    }

    private static function lostConnectionDetector(): LostConnectionDetectorContract
    {
        $container = Container::getInstance();

        $detector = $container->bound(LostConnectionDetectorContract::class)
            ? $container->make(LostConnectionDetectorContract::class)
            : new LostConnectionDetector;

        return $detector instanceof LostConnectionDetectorContract
            ? $detector
            : new LostConnectionDetector;
    }
}
