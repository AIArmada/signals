<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordApplicationSubmittedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $application = $event->application ?? null;

        if (! is_object($application)) {
            return;
        }

        $this->recorder->recordApplicationSubmitted($application);
    }
}
