<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\AlertEventEvaluation;
use AIArmada\Signals\Features\Alerts\EntryPoints\Jobs\EvaluateSignalAlertsForEvent;
use RuntimeException;

final readonly class EvaluateIngestedEvent implements AlertEventEvaluation
{
    public function afterIngest(string $eventId, string $trackedPropertyId, ?string $ownerType, string | int | null $ownerId): void
    {
        if (! (bool) config('signals.features.alerts.evaluate_on_ingest.enabled', false)) {
            return;
        }

        if ((bool) config('signals.features.alerts.evaluate_on_ingest.queue', true)) {
            EvaluateSignalAlertsForEvent::dispatch(
                signalEventId: (string) $eventId,
                ownerType: $ownerType,
                ownerId: $ownerId,
                ownerIsGlobal: $ownerType === null && $ownerId === null,
            );

            return;
        }

        $owner = OwnerContext::fromTypeAndId($ownerType, $ownerId);

        if (($ownerType !== null || $ownerId !== null) && $owner === null) {
            throw new RuntimeException('Signal event owner cannot be resolved.');
        }

        OwnerContext::withOwner($owner, fn () => app(EvaluateAlertRules::class)->handle($trackedPropertyId));
    }
}
