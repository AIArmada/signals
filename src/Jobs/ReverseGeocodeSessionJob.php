<?php

declare(strict_types=1);

namespace AIArmada\Signals\Jobs;

use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Services\SignalLocationResolverPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReverseGeocodeSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $sessionId,
    ) {}

    public function handle(SignalLocationResolverPipeline $pipeline): void
    {
        $session = SignalSession::find($this->sessionId);

        if ($session === null) {
            return;
        }

        if ($session->latitude === null || $session->longitude === null) {
            return;
        }

        if ($session->reverse_geocoded_at !== null) {
            return;
        }

        $pipeline->run($session);
    }
}
