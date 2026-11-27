<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\EntryPoints\Jobs;

use AIArmada\CommerceSupport\Contracts\OwnerScopedJob;
use AIArmada\CommerceSupport\Data\OwnerJobContext;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerJobContextMapper;
use AIArmada\CommerceSupport\Traits\OwnerContextJob;
use AIArmada\Signals\Features\Alerts\Actions\ProcessAlertDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class DispatchSignalAlertDelivery implements OwnerScopedJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use OwnerContextJob;
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public string $deliveryId,
        public ?string $ownerType,
        public string | int | null $ownerId,
        public bool $ownerIsGlobal = false,
    ) {
        $transportAttempts = max(1, (int) config('signals.features.alerts.delivery.max_attempts', 5));
        $this->tries = (2 * $transportAttempts) + 1;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $configured = config('signals.features.alerts.delivery.backoff_seconds', [10, 30, 120, 300]);

        return is_array($configured)
            ? array_values(array_map('intval', array_filter($configured, 'is_numeric')))
            : [10, 30, 120, 300];
    }

    public function ownerContext(): OwnerJobContext
    {
        return new OwnerJobContext($this->ownerType, $this->ownerId, $this->ownerIsGlobal);
    }

    protected function performJob(): void
    {
        $retryAfter = app(ProcessAlertDelivery::class)->run($this->deliveryId);

        if ($retryAfter !== null && $this->job !== null) {
            $this->release($retryAfter);
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            $context = $this->ownerContext();
            $owner = $context->isExplicitGlobal() ? null : OwnerJobContextMapper::resolve($context);
        } catch (Throwable) {
            return;
        }

        if (! $context->isExplicitGlobal() && $owner === null) {
            return;
        }

        OwnerContext::withOwner($owner, function () use ($exception): void {
            app(ProcessAlertDelivery::class)->fail($this->deliveryId, $exception);
        });
    }
}
