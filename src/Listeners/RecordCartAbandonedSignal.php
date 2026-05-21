<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;

final class RecordCartAbandonedSignal
{
    public function __construct(private readonly CommerceSignalsRecorder $recorder) {}

    public function handle(object $event): void
    {
        $this->recorder->recordCartAbandoned($event);
    }
}
