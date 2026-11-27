<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Api;

interface DetachPropertyAlerts
{
    public function detach(string $trackedPropertyId): void;
}
