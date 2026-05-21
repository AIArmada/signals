<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\Signals\Models\SignalAlertLog;
use Illuminate\Database\Eloquent\Collection;

final class MarkAllSignalAlertsAsRead
{
    /**
     * @param  Collection<int, SignalAlertLog>  $alertLogs
     */
    public function __invoke(Collection $alertLogs): void
    {
        $alertLogs->each(static function (SignalAlertLog $alertLog): void {
            $alertLog->markAsRead();
        });
    }
}
