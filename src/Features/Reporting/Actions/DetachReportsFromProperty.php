<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Reporting\Api\DetachPropertyReports;
use AIArmada\Signals\Features\Reporting\Models\SavedSignalReport;
use AIArmada\Signals\Features\Reporting\Models\SignalDailyMetric;
use AIArmada\Signals\Features\Reporting\Models\SignalGoal;

final readonly class DetachReportsFromProperty implements DetachPropertyReports
{
    public function detach(string $trackedPropertyId): void
    {
        (new SignalGoal)->getConnection()->transaction(function () use ($trackedPropertyId): void {
            $goals = SignalGoal::query()->where('tracked_property_id', $trackedPropertyId);
            $reports = SavedSignalReport::query()->where('tracked_property_id', $trackedPropertyId);
            $metrics = SignalDailyMetric::query()->where('tracked_property_id', $trackedPropertyId);

            if (SignalGoal::ownerScopingEnabled()) {
                $owner = OwnerContext::resolve();
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Detaching reports requires owner context.');
                $goals->forOwner($owner, includeGlobal: false);
                $reports->forOwner($owner, includeGlobal: false);
                $metrics->forOwner($owner, includeGlobal: false);
            }

            $goals->update(['tracked_property_id' => null]);
            $reports->update(['tracked_property_id' => null]);
            $metrics->delete();
        });
    }
}
