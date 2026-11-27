<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\AlertLogAdministration;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertDelivery;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;

final readonly class DeleteAlertLog implements AlertLogAdministration
{
    public function delete(string $id): bool
    {
        $connection = (new SignalAlertLog)->getConnection();

        return (bool) $connection->transaction(function () use ($id): bool {
            $logs = SignalAlertLog::query();
            $deliveries = SignalAlertDelivery::query();

            if (SignalAlertLog::ownerScopingEnabled()) {
                $owner = OwnerContext::resolve();
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Deleting an alert log requires owner context.');

                $logs = $owner === null ? $logs->globalOnly() : $logs->forOwner($owner, includeGlobal: false);
                $deliveries = $owner === null ? $deliveries->globalOnly() : $deliveries->forOwner($owner, includeGlobal: false);
            }

            $log = $logs->lockForUpdate()->find($id);

            if (! $log instanceof SignalAlertLog) {
                return false;
            }

            $deliveries->where('signal_alert_log_id', $log->getKey())->delete();

            return $log->delete() === true;
        }, attempts: 3);
    }
}
