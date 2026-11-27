<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

interface SignalIngestion
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws AuthorizationException When the tracked property is outside the current owner scope.
     * @throws InvalidArgumentException When the tracked property is inactive, its id is malformed or unknown, or the event name is invalid.
     * @throws ValidationException When an untrusted payload violates the browser event policy.
     */
    public function handle(string $trackedPropertyId, array $payload, bool $trusted): IngestedSignalEvent;
}
