<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;
use Illuminate\Database\Eloquent\Model;

final class RecordCheckoutCompletedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $session = $event->session ?? null;

        if (! $session instanceof Model) {
            return;
        }

        $this->recorder->recordCheckoutCompleted($session);
    }
}
