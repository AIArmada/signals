<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Api;

interface AlertReadState
{
    /** @param list<string> $alertIds */
    public function set(array $alertIds, bool $read): void;
}
