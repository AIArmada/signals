<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

interface SignalIngestion
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws AuthorizationException When the tracked property is outside the current owner scope.
     * @throws InvalidArgumentException When the tracked property id is malformed or unknown.
     */
    public function handle(string $trackedPropertyId, array $payload, bool $trusted): IngestedSignalEvent;
}
