<?php

declare(strict_types=1);

namespace AIArmada\Signals\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reported when an automatic commerce signal recording attempt fails.
 *
 * Automatic listeners report recoverable recording failures with bounded
 * diagnostic context instead of rethrowing them. Transaction-recovery
 * failures still propagate so the dispatching caller fails honestly:
 * callback-originated concurrency errors, lost connections, and
 * deadlocks propagate from the outer-transaction branch, while without
 * an outer transaction the ingestor first exhausts its bounded retries
 * and terminal callback-originated failures are then reported and
 * skipped. Control-originated transaction-control failures and disturbed
 * transaction state propagate unreported from both branches. Direct
 * recorder calls keep throwing loudly. The retained previous chain is
 * subject to the host application's exception-reporting redaction
 * policy.
 */
final class CommerceSignalRecordingFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(string $message, private readonly array $context, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function forAutomaticRecording(
        string $sourceEventClass,
        string $recorderMethod,
        string $phase,
        array $context,
        Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'Automatic commerce signal recording failed for event [%s] via [%s] during [%s] (%s; see previous exception for details).',
                $sourceEventClass,
                $recorderMethod,
                $phase,
                $previous::class,
            ),
            array_filter([
                'source_event_class' => $sourceEventClass,
                'recorder_method' => $recorderMethod,
                'phase' => $phase,
                'integration' => self::integrationFromEventClass($sourceEventClass),
            ], static fn (mixed $value): bool => $value !== null) + $context,
            $previous,
        );
    }

    public static function forAlertEvaluation(
        string $eventId,
        string $trackedPropertyId,
        mixed $ownerType,
        mixed $ownerId,
        Throwable $previous,
    ): self {

        return new self(
            sprintf(
                'Signal alert evaluation failed for persisted event [%s] (%s; see previous exception for details).',
                $eventId,
                $previous::class,
            ),
            array_filter([
                'phase' => 'alert_evaluation',
                'signal_event_id' => $eventId,
                'tracked_property_id' => $trackedPropertyId,
                'owner_type' => self::rawScalar($ownerType),
                'owner_id' => self::rawScalar($ownerId),
            ], static fn (mixed $value): bool => $value !== null),
            $previous,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public static function reportSafely(self $failure): void
    {
        try {
            report($failure);
        } catch (Throwable) {
            // Reporting must never turn a recoverable analytics failure
            // into a dispatch failure.
        }
    }

    private static function integrationFromEventClass(string $eventClass): ?string
    {
        $segments = explode('\\', mb_ltrim($eventClass, '\\'));

        if (($segments[0] ?? null) !== 'AIArmada' || ! isset($segments[1]) || $segments[1] === '') {
            return null;
        }

        return Str::snake($segments[1]);
    }

    private static function rawScalar(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }
}
