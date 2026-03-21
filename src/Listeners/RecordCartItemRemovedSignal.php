<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordCartItemRemovedSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $cart = $event->cart ?? null;
        $item = $event->item ?? null;

        if (! is_object($cart) || ! is_object($item)) {
            return;
        }

        $this->recorder->recordCartItemRemoved($cart, $item);
    }
}
