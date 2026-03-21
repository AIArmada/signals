<?php

declare(strict_types=1);

namespace AIArmada\Signals\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use AIArmada\Signals\Services\SignalAlertEvaluator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

final class ProcessSignalAlertsCommand extends Command
{
    protected $signature = 'signals:process-alerts
                            {--rule= : Process a specific alert rule by ID}
                            {--dry-run : Evaluate rules without creating alert logs}';

    protected $description = 'Evaluate and dispatch Signals alert rules';

    public function __construct(
        private readonly SignalAlertEvaluator $evaluator,
        private readonly SignalAlertDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->processForOwners(
            is_string($this->option('rule')) ? $this->option('rule') : null,
            (bool) $this->option('dry-run'),
        );

        $this->newLine();
        $this->info("Summary: {$summary['processed']} processed, {$summary['skipped']} skipped, {$summary['dispatched']} dispatched");

        return self::SUCCESS;
    }

    /**
     * @return array{processed:int,skipped:int,dispatched:int}
     */
    private function processForOwners(?string $ruleId, bool $dryRun): array
    {
        if (! SignalAlertRule::ownerScopingEnabled() || OwnerContext::resolve() !== null) {
            return $this->processScoped($ruleId, $dryRun);
        }

        $owners = SignalAlertRule::query()
            ->withoutOwnerScope()
            ->select(['owner_type', 'owner_id'])
            ->distinct()
            ->get();

        if ($owners->isEmpty()) {
            return $this->processScoped($ruleId, $dryRun);
        }

        $totals = ['processed' => 0, 'skipped' => 0, 'dispatched' => 0];

        foreach ($owners as $row) {
            $owner = $this->resolveOwnerFromRow($row);

            $result = OwnerContext::withOwner(
                $owner,
                fn (): array => $this->processScoped($ruleId, $dryRun)
            );

            $totals['processed'] += $result['processed'];
            $totals['skipped'] += $result['skipped'];
            $totals['dispatched'] += $result['dispatched'];
        }

        return $totals;
    }

    /**
     * @return array{processed:int,skipped:int,dispatched:int}
     */
    private function processScoped(?string $ruleId, bool $dryRun): array
    {
        $query = SignalAlertRule::query()->forOwner()->where('is_active', true);

        if ($ruleId !== null && $ruleId !== '') {
            $query->whereKey($ruleId);
        }

        $rules = $query->orderByDesc('priority')->get();

        if ($rules->isEmpty()) {
            $this->line('No active signal alert rules found.');

            return ['processed' => 0, 'skipped' => 0, 'dispatched' => 0];
        }

        $processed = 0;
        $skipped = 0;
        $dispatched = 0;

        foreach ($rules as $rule) {
            if ($rule->isInCooldown()) {
                $skipped++;

                continue;
            }

            $result = $this->evaluator->evaluate($rule);
            $processed++;

            if (! $result['matched']) {
                continue;
            }

            if (! $dryRun) {
                $this->dispatcher->dispatch($rule, $result['metric_value'], $result['context']);
                $dispatched++;
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped, 'dispatched' => $dispatched];
    }

    private function resolveOwnerFromRow(object $row): ?Model
    {
        $ownerType = $row->owner_type ?? null;
        $ownerId = $row->owner_id ?? null;

        return OwnerContext::fromTypeAndId(
            is_string($ownerType) ? $ownerType : null,
            is_string($ownerId) || is_int($ownerId) ? $ownerId : null,
        );
    }
}
