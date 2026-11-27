<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\DetachPropertyAlerts;
use AIArmada\Signals\Features\Ingestion\Api\TrackedPropertyDeleting;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use AIArmada\Signals\Features\Reporting\Api\DetachPropertyReports;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class CleanUpTrackedProperty
{
    public function __construct(
        private DetachPropertyReports $reports,
        private DetachPropertyAlerts $alerts,
    ) {}

    public function handle(TrackedProperty $property): void
    {
        $property->getConnection()->transaction(function () use ($property): void {
            if ($property::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolve();
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Deleting a tracked property requires owner context.');

                if ($property->owner_type !== $owner?->getMorphClass()
                    || (string) $property->owner_id !== (string) $owner?->getKey()) {
                    throw new AuthorizationException('Tracked property is outside the current owner scope.');
                }
            }

            $id = (string) $property->getKey();
            event(new TrackedPropertyDeleting($id, $property->owner_type, $property->owner_id));

            $this->reports->detach($id);
            $this->alerts->detach($id);
            $property->events()->delete();
            $property->interactionRules()->delete();
            $property->sessions()->delete();
            $property->identities()->delete();
        });
    }
}
