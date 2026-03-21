<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordAffiliateConversionRecordedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $conversion = $event->conversion ?? null;

        if (! is_object($conversion)) {
            return;
        }

        $this->recorder->recordAffiliateConversionRecorded($conversion);
    }
}
