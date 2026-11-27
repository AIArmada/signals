<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Api;

interface DetachPropertyReports
{
    public function detach(string $trackedPropertyId): void;
}
