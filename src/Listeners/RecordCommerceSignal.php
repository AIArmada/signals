<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Exceptions\CommerceSignalRecordingFailed;
use AIArmada\Signals\Exceptions\CommerceSignalTransactionControlFailed;
use AIArmada\Signals\Services\CommerceSignalsRecorder;
use AIArmada\Signals\Support\SignalEventMap;
use AIArmada\Signals\Support\TransactionFailureClassifier;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class RecordCommerceSignal
{
    public function __construct(private readonly CommerceSignalsRecorder $recorder) {}

    public function handle(object $event): void
    {
        $mapping = SignalEventMap::for($event::class);

        if ($mapping === null) {
            return;
        }

        $entryLevel = DB::transactionLevel();
        $entryServerTransaction = $this->serverInTransaction();
        $entryPdo = $this->currentPdo();

        if ($entryLevel > 0) {
            // Nested savepoint: a failed recording attempt rolls back its
            // own writes without poisoning the dispatcher's transaction.
            try {
                DB::transaction(function () use ($event, $mapping): void {
                    $this->attempt($event, $mapping);
                }, 1);
            } catch (CommerceSignalRecordingFailed $e) {
                CommerceSignalRecordingFailed::reportSafely($e);
                $this->assertTransactionStateRestored($event, $mapping, $entryLevel, $entryServerTransaction, $entryPdo, $e);

                return;
            } catch (DeadlockException $e) {
                throw TransactionFailureClassifier::unwrapForHostRetry($e);
            } catch (CommerceSignalTransactionControlFailed $e) {
                throw TransactionFailureClassifier::unwrapMarkerForHostRetry($e);
            }

            $this->assertTransactionStateRestored($event, $mapping, $entryLevel, $entryServerTransaction, $entryPdo);

            // Anything else propagates: concurrency and lost-connection
            // errors belong to the enclosing transaction, and this
            // savepoint's own machinery failures leave the host
            // transaction state unknown.
            return;
        }

        try {
            $this->attempt($event, $mapping);
        } catch (CommerceSignalRecordingFailed $e) {
            CommerceSignalRecordingFailed::reportSafely($e);
            $this->assertTransactionStateRestored($event, $mapping, $entryLevel, $entryServerTransaction, $entryPdo, $e);

            return;
        } catch (CommerceSignalTransactionControlFailed $e) {
            // Recording-owned transaction machinery failed: propagate
            // unreported. Matching bookkeeping cannot make an
            // infrastructure failure recoverable.
            throw $e;
        } catch (Throwable $e) {
            // No outer transaction owns recovery, and the ingestor already
            // exhausted its bounded retries, so report and continue.
            $this->reportUnclassified($event, $mapping, $e);
            $this->assertTransactionStateRestored($event, $mapping, $entryLevel, $entryServerTransaction, $entryPdo, $e);

            return;
        }

        $this->assertTransactionStateRestored($event, $mapping, $entryLevel, $entryServerTransaction, $entryPdo);
    }

    /**
     * @param  array{method: string, arguments: list<array{property: string|null, type: string}>}  $mapping
     */
    private function attempt(object $event, array $mapping): void
    {
        $phase = 'mapping';
        $resolved = [];

        try {
            $resolved = $this->resolveArguments($event, $mapping);

            if ($resolved === null) {
                return;
            }

            $phase = 'recording';

            $this->recorder->{$mapping['method']}(...$resolved);
        } catch (Throwable $e) {
            if ($e instanceof CommerceSignalTransactionControlFailed
                || $e instanceof DeadlockException
                || TransactionFailureClassifier::causedByConcurrencyError($e)
                || TransactionFailureClassifier::causedByLostConnection($e)) {
                // Marked transaction-control failures propagate untouched:
                // matching bookkeeping cannot make an infrastructure
                // failure recoverable. Let the caller classify the
                // original database exception so enclosing transactions
                // can retry correctly. A lost connection is equally
                // unrecoverable here: Laravel may have replaced the
                // connection, leaving bookkeeping behind.
                throw $e;
            }

            throw CommerceSignalRecordingFailed::forAutomaticRecording(
                $event::class,
                $mapping['method'],
                $phase,
                $this->diagnosticContext($resolved),
                $e,
            );
        }
    }

    /**
     * The listener may only swallow a failure — or return normally — when
     * the connection transaction state is exactly as found: same nesting
     * level, same server-side transaction presence, and same underlying
     * connection object. Anything else means a leaked level, a vanished
     * transaction, or a replaced connection. The entry PDO is retained for
     * the whole attempt, so a replacement is always a different instance:
     * comparing retained objects defeats object-ID reuse. The state change
     * itself is thrown, never reported here: the original failure was
     * already reported, and the host reports what it catches. The original
     * failure is preserved as the cause so the host sees the full chain.
     *
     * @param  array{method: string, arguments: list<array{property: string|null, type: string}>}  $mapping
     */
    private function assertTransactionStateRestored(
        object $event,
        array $mapping,
        int $entryLevel,
        ?bool $entryServerTransaction,
        ?PDO $entryPdo,
        ?Throwable $cause = null,
    ): void {
        $exitLevel = DB::transactionLevel();
        $exitServerTransaction = $this->serverInTransaction();
        $exitPdo = $this->currentPdo();

        if ($exitLevel === $entryLevel
            && $entryServerTransaction !== null
            && $exitServerTransaction !== null
            && $exitServerTransaction === $entryServerTransaction
            && $entryPdo !== null
            && $exitPdo !== null
            && $exitPdo === $entryPdo) {
            return;
        }

        $failure = CommerceSignalRecordingFailed::forAutomaticRecording(
            $event::class,
            (string) ($mapping['method'] ?? 'unknown'),
            'recording',
            array_filter([
                'entry_transaction_level' => $entryLevel,
                'exit_transaction_level' => $exitLevel,
                'entry_server_transaction' => $entryServerTransaction,
                'exit_server_transaction' => $exitServerTransaction,
                'entry_connection_id' => $entryPdo === null ? null : spl_object_id($entryPdo),
                'exit_connection_id' => $exitPdo === null ? null : spl_object_id($exitPdo),
            ], static fn (mixed $value): bool => $value !== null),
            $cause ?? new RuntimeException('Database transaction state changed during automatic signal recording.'),
        );

        throw $failure;
    }

    private function serverInTransaction(): ?bool
    {
        try {
            return DB::getPdo()->inTransaction();
        } catch (Throwable) {
            return null;
        }
    }

    private function currentPdo(): ?PDO
    {
        try {
            $pdo = DB::getPdo();
        } catch (Throwable) {
            return null;
        }

        return $pdo instanceof PDO ? $pdo : null;
    }

    /**
     * @param  array{method: string, arguments: list<array{property: string|null, type: string}>}  $mapping
     * @return list<mixed>|null Null when the event shape is not recordable and must stay a silent no-op.
     */
    private function resolveArguments(object $event, array $mapping): ?array
    {
        $arguments = [];

        foreach ($mapping['arguments'] as $argument) {
            $value = $argument['type'] === 'event'
                ? $event
                : (property_exists($event, (string) $argument['property']) ? $event->{$argument['property']} : null);

            $value = match ($argument['type']) {
                'event' => $value,
                'object' => is_object($value) ? $value : null,
                'model' => $value instanceof Model ? $value : null,
                'scalar' => $value === null || is_scalar($value) ? ($value === null ? null : (string) $value) : null,
                'required_scalar' => is_scalar($value) ? (string) $value : null,
                'numeric_int' => is_numeric($value) ? (int) $value : 0,
                'required_numeric_int' => is_numeric($value) ? (int) $value : null,
                default => null,
            };

            if (in_array($argument['type'], ['required_scalar', 'required_numeric_int'], true)) {
                if ($value === null || $value === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Event [%s] is missing required property [%s].',
                        $event::class,
                        (string) $argument['property'],
                    ));
                }

                $arguments[] = $value;

                continue;
            }

            if ($argument['type'] !== 'event' && $argument['type'] !== 'scalar' && $value === null) {
                return null;
            }

            $arguments[] = $value;
        }

        return $arguments;
    }

    /**
     * @param  array{method: string, arguments: list<array{property: string|null, type: string}>}  $mapping
     */
    private function reportUnclassified(object $event, array $mapping, Throwable $e): void
    {
        CommerceSignalRecordingFailed::reportSafely(
            CommerceSignalRecordingFailed::forAutomaticRecording(
                $event::class,
                (string) ($mapping['method'] ?? 'unknown'),
                'recording',
                [],
                $e,
            )
        );
    }

    /**
     * Bounded failure context: raw attributes only, no relationship or
     * accessor calls, so context assembly can never query or throw.
     *
     * @param  list<mixed>  $resolved
     * @return array<string, mixed>
     */
    private function diagnosticContext(array $resolved): array
    {
        try {
            foreach ($resolved as $value) {
                if (! $value instanceof Model) {
                    continue;
                }

                $attributes = $value->getAttributes();

                return array_filter([
                    'source_model_class' => $value::class,
                    'source_id' => $this->contextScalar($value->getKey()),
                    'owner_type' => $this->rawAttribute($attributes, 'owner_type'),
                    'owner_id' => $this->rawAttribute($attributes, 'owner_id'),
                ], static fn (mixed $value): bool => $value !== null);
            }
        } catch (Throwable) {
            // Context assembly must never fail the report.
        }

        return [];
    }

    private function contextScalar(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rawAttribute(array $attributes, string $key): ?string
    {
        if (! array_key_exists($key, $attributes)) {
            return null;
        }

        return $this->contextScalar($attributes[$key]);
    }
}
