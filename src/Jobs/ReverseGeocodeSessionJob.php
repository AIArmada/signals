<?php

declare(strict_types=1);

namespace AIArmada\Signals\Jobs;

use AIArmada\CommerceSupport\Contracts\OwnerScopedJob;
use AIArmada\CommerceSupport\Support\OwnerJobContext;
use AIArmada\CommerceSupport\Traits\OwnerContextJob;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Services\SignalLocationResolverPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

class ReverseGeocodeSessionJob implements OwnerScopedJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use OwnerContextJob;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $sessionId,
        public ?string $ownerType,
        public string | int | null $ownerId,
        public bool $ownerIsGlobal = false,
    ) {}

    public function ownerContext(): OwnerJobContext
    {
        return new OwnerJobContext(
            ownerType: $this->ownerType,
            ownerId: $this->ownerId,
            ownerIsGlobal: $this->ownerIsGlobal,
        );
    }

    protected function performJob(): void
    {
        $pipeline = app(SignalLocationResolverPipeline::class);

        $session = SignalSession::find($this->sessionId);

        if ($session === null) {
            if (SignalSession::query()->withoutOwnerScope()->whereKey($this->sessionId)->exists()) {
                throw new RuntimeException(
                    sprintf(
                        'Signal session owner context mismatch. [job=%s session_id=%s owner_type=%s owner_id=%s owner_is_global=%s]',
                        static::class,
                        $this->sessionId,
                        (string) ($this->ownerType ?? 'null'),
                        (string) ($this->ownerId ?? 'null'),
                        $this->ownerIsGlobal ? 'true' : 'false',
                    ),
                );
            }

            return;
        }

        if ($session->latitude === null || $session->longitude === null) {
            return;
        }

        if ($session->reverse_geocoded_at !== null) {
            return;
        }

        try {
            $pipeline->run($session);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    'Reverse geocode failed for session context. [job=%s session_id=%s owner_type=%s owner_id=%s owner_is_global=%s]',
                    static::class,
                    $this->sessionId,
                    (string) ($this->ownerType ?? 'null'),
                    (string) ($this->ownerId ?? 'null'),
                    $this->ownerIsGlobal ? 'true' : 'false',
                ),
                previous: $exception,
            );
        }
    }
}
