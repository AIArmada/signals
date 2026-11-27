<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\DetachPropertyAlerts;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;
use AIArmada\Signals\Features\Ingestion\Api\TelemetryRead;

final readonly class DetachAlertsFromProperty implements DetachPropertyAlerts
{
    public function detach(string $trackedPropertyId): void
    {
        $connection = (new SignalAlertRule)->getConnection();

        $connection->transaction(function () use ($trackedPropertyId): void {
            $rules = SignalAlertRule::query()->where('tracked_property_id', $trackedPropertyId);
            $logs = SignalAlertLog::query()->where('tracked_property_id', $trackedPropertyId);

            if (SignalAlertRule::ownerScopingEnabled()) {
                $owner = OwnerContext::resolve();
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Detaching alerts requires owner context.');
                $rules->forOwner($owner, includeGlobal: false);
                $logs->forOwner($owner, includeGlobal: false);
            }

            $propertyCurrency = app(TelemetryRead::class)->propertyCurrency($trackedPropertyId);

            foreach ($rules->lockForUpdate()->get(['id', 'metric_key', 'event_filters']) as $rule) {
                $attributes = [
                    'tracked_property_id' => null,
                    'is_active' => false,
                ];

                if ($rule->metric_key === 'revenue_minor' && $propertyCurrency !== null) {
                    $filters = is_array($rule->event_filters) ? $rule->event_filters : [];
                    $filters['currency'] = $propertyCurrency;
                    $attributes['event_filters'] = $filters;
                }

                SignalAlertRule::query()->whereKey($rule->getKey())->update($attributes);
            }

            $logs->update(['tracked_property_id' => null]);
        });
    }
}
