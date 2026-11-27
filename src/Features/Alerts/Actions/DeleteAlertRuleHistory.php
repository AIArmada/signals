<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertDelivery;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;

final readonly class DeleteAlertRuleHistory
{
    public function handle(SignalAlertRule $rule): void
    {
        $rule->getConnection()->transaction(function () use ($rule): void {
            $logs = SignalAlertLog::query()->where('signal_alert_rule_id', $rule->getKey());
            $deliveries = SignalAlertDelivery::query();

            if (SignalAlertRule::ownerScopingEnabled()) {
                $owner = OwnerContext::resolve();
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Alert rule deletion requires owner context.');
                $logs->forOwner($owner, includeGlobal: false);
                $deliveries->forOwner($owner, includeGlobal: false);
            }

            $deliveries->whereIn('signal_alert_log_id', (clone $logs)->select('id'))
                ->delete();

            $logs->delete();
        });
    }
}
