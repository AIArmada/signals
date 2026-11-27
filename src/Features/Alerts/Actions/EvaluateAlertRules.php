<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\CommerceSupport\Support\OwnerBatchRunner;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;
use AIArmada\Signals\Features\Alerts\Queries\AlertEvaluator;
use AIArmada\Signals\Features\Ingestion\Api\TelemetryRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class EvaluateAlertRules
{
    public function __construct(
        private AlertEvaluator $evaluator,
        private SignalAlertDispatcher $dispatcher,
        private TelemetryRead $telemetry,
    ) {}

    /**
     * @return array{processed: int, skipped: int, dispatched: int}
     */
    public function handle(?string $trackedPropertyId = null, bool $dryRun = false, ?string $ruleId = null): array
    {
        return $this->processRules(
            SignalAlertRule::query()
                ->forOwner()
                ->when(filled($ruleId), fn (Builder $query): Builder => $query->whereKey($ruleId))
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
                ->orderByDesc('priority')
                ->orderBy('id'),
            $dryRun,
        );
    }

    /** @return array{processed: int, skipped: int, dispatched: int} */
    public function forOwners(?string $ruleId, bool $dryRun): array
    {
        if (OwnerContext::isExplicitGlobal()) {
            return $this->handle(dryRun: $dryRun, ruleId: $ruleId);
        }

        $runner = new OwnerBatchRunner(SignalAlertRule::class, ['enabled' => 'signals.owner.enabled']);

        return $runner->run(fn (): array => $this->handle(dryRun: $dryRun, ruleId: $ruleId))
            ?? ['processed' => 0, 'skipped' => 0, 'dispatched' => 0];
    }

    /**
     * @param  Builder<SignalAlertRule>  $query
     * @return array{processed: int, skipped: int, dispatched: int}
     */
    private function processRules(Builder $query, bool $dryRun): array
    {
        $processed = 0;
        $skipped = 0;
        $dispatched = 0;

        $query->chunk(100, function (Collection $rules) use ($dryRun, &$processed, &$skipped, &$dispatched): void {
            $currencyIds = $rules
                ->filter(static fn (SignalAlertRule $rule): bool => $rule->metric_key === 'revenue_minor' && filled($rule->tracked_property_id))
                ->map(static fn (SignalAlertRule $rule): string => (string) $rule->tracked_property_id)
                ->unique()
                ->values()
                ->all();
            $currencies = $this->telemetry->propertyDetails($currencyIds);

            foreach ($rules as $rule) {
                if ($rule->isInCooldown()) {
                    $skipped++;

                    continue;
                }

                $revenueCurrency = null;

                if ($rule->metric_key === 'revenue_minor' && filled($rule->tracked_property_id)) {
                    $revenueCurrency = $currencies[(string) $rule->tracked_property_id]->currency ?? null;

                    if ($revenueCurrency === null) {
                        $skipped++;

                        continue;
                    }
                }

                $result = $this->evaluator->evaluate($rule, $revenueCurrency);
                $processed++;

                if (! $result['matched']) {
                    continue;
                }

                if (! $dryRun) {
                    if ($this->dispatcher->dispatch($rule, $result['metric_value'], $result['context']) !== null) {
                        $dispatched++;
                    } else {
                        $skipped++;
                    }
                }
            }
        });

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'dispatched' => $dispatched,
        ];
    }
}
