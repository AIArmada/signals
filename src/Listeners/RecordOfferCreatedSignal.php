<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordOfferCreatedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $offer = $event->offer ?? null;

        if (! is_object($offer)) {
            return;
        }

        $this->recorder->recordOfferCreated($offer);
    }
}
