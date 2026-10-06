<?php

declare(strict_types=1);

namespace AIArmada\Signals\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Marks a transaction-control failure at a recording-owned boundary.
 *
 * Begin, savepoint, commit, and rollback machinery failures are
 * infrastructure failures: they must propagate to the dispatching caller
 * unreported so the host transaction contract stays honest. The automatic
 * listener never wraps, reports, or swallows this marker; without an
 * outer transaction the marker itself crosses the boundary, while inside
 * an outer transaction a marker with a recognized contention cause is
 * unwrapped to that cause so the host keeps its retry classification.
 * The retained previous chain is subject to the host application's
 * exception-reporting redaction policy.
 */
final class CommerceSignalTransactionControlFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(string $message, private readonly array $context, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function fromControlFailure(Throwable $controlFailure, ?Throwable $callbackFailure = null): self
    {
        return new self(
            sprintf(
                'Signal recording transaction control failed (%s; see previous exception for details).',
                $controlFailure::class,
            ),
            array_filter([
                'control_failure_class' => $controlFailure::class,
                'callback_failure_class' => $callbackFailure === null ? null : $callbackFailure::class,
            ], static fn (mixed $value): bool => $value !== null),
            $controlFailure,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
