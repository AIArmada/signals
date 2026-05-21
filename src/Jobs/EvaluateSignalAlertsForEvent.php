<?php

declare(strict_types=1);

namespace AIArmada\Signals\Jobs;

use AIArmada\CommerceSupport\Contracts\OwnerScopedJob;
use AIArmada\CommerceSupport\Support\OwnerJobContext;
use AIArmada\CommerceSupport\Traits\OwnerContextJob;
use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use AIArmada\Signals\Services\SignalAlertEvaluator;
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
        $evaluator = app(SignalAlertEvaluator::class);
        $dispatcher = app(SignalAlertDispatcher::class);

        $event = SignalEvent::query()
            ->find($this->signalEventId);

        if (! $event instanceof SignalEvent) {
            if (SignalEvent::query()->withoutOwnerScope()->whereKey($this->signalEventId)->exists()) {
                throw new RuntimeException(
                    sprintf(
                        'Signal event owner context mismatch. [job=%s signal_event_id=%s owner_type=%s owner_id=%s owner_is_global=%s]',
                        static::class,
                        $this->signalEventId,
                        (string) ($this->ownerType ?? 'null'),
                        (string) ($this->ownerId ?? 'null'),
                        $this->ownerIsGlobal ? 'true' : 'false',
                    ),
                );
            }

            return;
        }

        try {
            SignalAlertRule::query()
                ->where('is_active', true)
                ->where(function ($query) use ($event): void {
                    $query->whereNull('tracked_property_id')
                        ->orWhere('tracked_property_id', $event->tracked_property_id);
                })
                ->orderByDesc('priority')
                ->each(function (SignalAlertRule $rule) use ($evaluator, $dispatcher): void {
                    if ($rule->isInCooldown()) {
                        return;
                    }

                    $result = $evaluator->evaluate($rule);

                    if (! $result['matched']) {
                        return;
                    }

                    $dispatcher->dispatch($rule, $result['metric_value'], $result['context']);
                });
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
