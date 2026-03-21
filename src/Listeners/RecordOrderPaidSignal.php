<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;
use Illuminate\Database\Eloquent\Model;

final class RecordOrderPaidSignal
{
    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
    ) {}

    public function handle(object $event): void
    {
        $order = $event->order ?? null;

        if (! $order instanceof Model) {
            return;
        }

        $transactionId = $event->transactionId ?? null;
        $gateway = $event->gateway ?? null;

        $this->recorder->recordOrderPaid(
            $order,
            is_scalar($transactionId) ? (string) $transactionId : null,
            is_scalar($gateway) ? (string) $gateway : null,
        );
    }
}
