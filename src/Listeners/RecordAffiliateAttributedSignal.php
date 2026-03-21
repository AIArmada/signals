<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordAffiliateAttributedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $attribution = $event->attribution ?? null;

        if (! is_object($attribution)) {
            return;
        }

        $this->recorder->recordAffiliateAttributed($attribution);
    }
}
