<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\EntryPoints\Console;

use AIArmada\Signals\Features\Reporting\Actions\AggregateDailyMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class AggregateDailyMetricsCommand extends Command
{
    protected $signature = 'signals:aggregate-daily
                            {--date= : Specific date to aggregate (Y-m-d)}
                            {--from= : Start date for a range (Y-m-d)}
                            {--to= : End date for a range (Y-m-d)}
                            {--days=1 : Number of days to aggregate from today}';

    protected $description = 'Aggregate daily Signals metrics from raw events and sessions';

    public function handle(AggregateDailyMetrics $aggregator): int
    {
        $this->runAggregation($aggregator);

        return self::SUCCESS;
    }

    private function runAggregation(AggregateDailyMetrics $aggregator): void
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        $days = (int) $this->option('days');

        if (is_string($date) && $date !== '') {
            $day = CarbonImmutable::parse($date);
            $count = $aggregator->aggregateForOwners($day, $day);
            $this->info("Aggregated {$count} daily metric rows for {$date}.");

            return;
        }

        if (is_string($from) && is_string($to) && $from !== '' && $to !== '') {
            $count = $aggregator->aggregateForOwners(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
            $this->info("Aggregated {$count} daily metric rows from {$from} to {$to}.");

            return;
        }

        $end = CarbonImmutable::today();
        $start = $end->subDays(max($days - 1, 0));
        $count = $aggregator->aggregateForOwners($start, $end);

        $this->info("Aggregated {$count} daily metric rows from {$start->toDateString()} to {$end->toDateString()}.");
    }
}
