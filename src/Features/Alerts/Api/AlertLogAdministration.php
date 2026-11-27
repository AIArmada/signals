<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Api;

interface AlertLogAdministration
{
    public function delete(string $id): bool;
}
