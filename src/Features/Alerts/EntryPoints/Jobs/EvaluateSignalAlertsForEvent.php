<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\EntryPoints\Jobs;

use AIArmada\CommerceSupport\Contracts\OwnerScopedJob;
use AIArmada\CommerceSupport\Data\OwnerJobContext;
use AIArmada\CommerceSupport\Traits\OwnerContextJob;
use AIArmada\Signals\Features\Alerts\Actions\EvaluateAlertRules;
use AIArmada\Signals\Features\Ingestion\Api\EventPropertyLookup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

final class EvaluateSignalAlertsForEvent implements OwnerScopedJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use OwnerContextJob;
    use Queueable;

    public function __construct(
        public string $signalEventId,
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
        $propertyId = app(EventPropertyLookup::class)->propertyId($this->signalEventId);

        if ($propertyId === null) {
            return;
        }

        try {
            app(EvaluateAlertRules::class)->handle($propertyId);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    'Signal alert evaluation failed for event context. [job=%s signal_event_id=%s owner_type=%s owner_id=%s owner_is_global=%s]',
                    static::class,
                    $this->signalEventId,
                    (string) ($this->ownerType ?? 'null'),
                    (string) ($this->ownerId ?? 'null'),
                    $this->ownerIsGlobal ? 'true' : 'false',
                ),
                previous: $exception,
            );
        }
    }
}
