<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Api;

interface AlertSummary
{
    /** @return array{active_alert_rules:int,unread_alerts:int} */
    public function counts(?string $trackedPropertyId = null): array;
}
