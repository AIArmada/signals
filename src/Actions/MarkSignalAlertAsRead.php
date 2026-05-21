<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\Signals\Models\SignalAlertLog;

final class MarkSignalAlertAsRead
{
    public function __invoke(SignalAlertLog $alertLog): void
    {
        $alertLog->markAsRead();
    }
}
