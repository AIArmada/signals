<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use AIArmada\Signals\Services\SignalAlertEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final class EvaluateAlertRules
{
    use AsAction;

    public function __construct(
        private readonly SignalAlertEvaluator $evaluator,
        private readonly SignalAlertDispatcher $dispatcher,
    ) {}

    /**
     * @return array{processed: int, skipped: int, dispatched: int}
     */
    public function handle(?string $trackedPropertyId = null, bool $dryRun = false): array
    {
        return $this->processRules(
            SignalAlertRule::query()
                ->where('is_active', true)
                ->when(
                    filled($trackedPropertyId),
                    function (Builder $query) use ($trackedPropertyId): Builder {
                        return $query->where(function (Builder $q) use ($trackedPropertyId): void {
                            $q->whereNull('tracked_property_id')
                                ->orWhere('tracked_property_id', $trackedPropertyId);
                        });
                    },
                )
                ->orderByDesc('priority'),
            $dryRun,
        );
    }

    /**
     * @return array{processed: int, skipped: int, dispatched: int}
     */
    public function handleRules(Builder $query, bool $dryRun = false): array
    {
        return $this->processRules($query, $dryRun);
    }

    /**
     * @return array{processed: int, skipped: int, dispatched: int}
     */
    private function processRules(Builder $query, bool $dryRun): array
    {
        $processed = 0;
        $skipped = 0;
        $dispatched = 0;

        $query->each(function (Model $model) use ($dryRun, &$processed, &$skipped, &$dispatched): void {
            $rule = $model instanceof SignalAlertRule ? $model : null;

            if ($rule === null) {
                return;
            }

            if ($rule->isInCooldown()) {
                $skipped++;

                return;
            }

            $result = $this->evaluator->evaluate($rule);
            $processed++;

            if (! $result['matched']) {
                return;
            }

            if (! $dryRun) {
                $this->dispatcher->dispatch($rule, $result['metric_value'], $result['context']);
                $dispatched++;
            }
        });

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'dispatched' => $dispatched,
        ];
    }
}
