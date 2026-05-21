<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;
use Illuminate\Database\Eloquent\Model;

final class RecordOrderRefundedSignal
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

        $amount = $event->amount ?? 0;
        $reason = $event->reason ?? null;

        $this->recorder->recordOrderRefunded(
            $order,
            is_numeric($amount) ? (int) $amount : 0,
            is_scalar($reason) ? (string) $reason : null,
        );
    }
}
