<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

use AIArmada\Signals\Data\SessionLocation;

interface SignalLocationResolverContract
{
    /**
     * Enrich application-owned data for the supplied location.
     * Implementations must be idempotent when a failed operation is retried.
     */
    public function resolve(SessionLocation $location): void;
}
