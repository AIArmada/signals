<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\SignalSession;

final readonly class DetachDeletedTelemetry
{
    public function session(string $sessionId): void
    {
        SignalEvent::query()->where('signal_session_id', $sessionId)->update(['signal_session_id' => null]);
    }

    public function identity(string $identityId): void
    {
        (new SignalSession)->getConnection()->transaction(function () use ($identityId): void {
            SignalSession::query()->where('signal_identity_id', $identityId)->update(['signal_identity_id' => null]);
            SignalEvent::query()->where('signal_identity_id', $identityId)->update(['signal_identity_id' => null]);
        });
    }
}
