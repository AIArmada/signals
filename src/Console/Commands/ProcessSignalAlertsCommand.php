<?php

declare(strict_types=1);

namespace AIArmada\Signals\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerBatchRunner;
use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use AIArmada\Signals\Services\SignalAlertEvaluator;
use Illuminate\Console\Command;

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
        $runner = new OwnerBatchRunner(
            SignalAlertRule::class,
            ['enabled' => 'signals.owner.enabled'],
        );

        return $runner->run(fn (): array => $this->processScoped($ruleId, $dryRun)) ?? [
            'processed' => 0,
            'skipped' => 0,
            'dispatched' => 0,
        ];
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

        if (! (clone $query)->exists()) {
            $this->line('No active signal alert rules found.');

            return ['processed' => 0, 'skipped' => 0, 'dispatched' => 0];
        }

        $summary = ['processed' => 0, 'skipped' => 0, 'dispatched' => 0];

        $query->orderBy('id')->chunkById(100, function ($rules) use ($dryRun, &$summary): void {
            foreach ($rules as $rule) {
                if ($rule->isInCooldown()) {
                    ++$summary['skipped'];

                    continue;
                }

                $result = $this->evaluator->evaluate($rule);
                ++$summary['processed'];

                if (! $result['matched']) {
                    continue;
                }

                if (! $dryRun) {
                    $this->dispatcher->dispatch($rule, $result['metric_value'], $result['context']);
                    ++$summary['dispatched'];
                }
            }
        }, 'id');

        return $summary;
    }
}
