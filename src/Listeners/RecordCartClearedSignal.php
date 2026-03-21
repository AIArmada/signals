<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordCartClearedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $cart = $event->cart ?? null;

        if (! is_object($cart)) {
            return;
        }

        $this->recorder->recordCartCleared($cart);
    }
}
