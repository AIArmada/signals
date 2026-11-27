<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Api;

interface AlertEventEvaluation
{
    public function afterIngest(string $eventId, string $trackedPropertyId, ?string $ownerType, string | int | null $ownerId): void;
}
