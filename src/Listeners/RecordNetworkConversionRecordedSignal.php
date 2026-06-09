<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordNetworkConversionRecordedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $link = $event->link ?? null;
        $revenueMinor = $event->revenueMinor ?? 0;

        if (! is_object($link)) {
            return;
        }

        $this->recorder->recordNetworkConversionRecorded($link, (int) $revenueMinor);
    }
}
