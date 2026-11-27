<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\AlertSummary;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;

final readonly class AlertSummaryQuery implements AlertSummary
{
    public function counts(?string $trackedPropertyId = null): array
    {
        $rules = SignalAlertRule::query()->where('is_active', true);
        $logs = SignalAlertLog::query()->whereNull('read_at');

        if (SignalAlertRule::ownerScopingEnabled()) {
            OwnerContext::assertResolvedOrExplicitGlobal(
                OwnerContext::resolve(),
                'Alert summary requires owner context.',
            );
        }

        if ($trackedPropertyId !== null) {
            $rules->where('tracked_property_id', $trackedPropertyId);
            $logs->where('tracked_property_id', $trackedPropertyId);
        }

        return ['active_alert_rules' => $rules->count(), 'unread_alerts' => $logs->count()];
    }
}
