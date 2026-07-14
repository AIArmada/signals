<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;

/**
 * Application-neutral seam for recording a trusted or untrusted signal event.
 */
interface SignalEventIngestor
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, array $payload, bool $trusted): SignalEvent;
}
