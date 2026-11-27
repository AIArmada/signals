<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\EntryPoints\Console;

use AIArmada\Signals\Features\Alerts\Actions\EvaluateAlertRules;
use Illuminate\Console\Command;

final class ProcessSignalAlertsCommand extends Command
{
    protected $signature = 'signals:process-alerts
                            {--rule= : Process a specific alert rule by ID}
                            {--dry-run : Evaluate rules without creating alert logs}';

    protected $description = 'Evaluate and dispatch Signals alert rules';

    public function handle(EvaluateAlertRules $rules): int
    {
        $summary = $rules->forOwners(
            is_string($this->option('rule')) ? $this->option('rule') : null,
            (bool) $this->option('dry-run'),
        );

        if ($summary['processed'] === 0 && $summary['skipped'] === 0) {
            $this->line('No active signal alert rules found.');
        }

        $this->newLine();
        $this->info("Summary: {$summary['processed']} processed, {$summary['skipped']} skipped, {$summary['dispatched']} dispatched");

        return self::SUCCESS;
    }
}
