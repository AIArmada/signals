<?php

declare(strict_types=1);

namespace AIArmada\Signals\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\SignalMetricsAggregator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class AggregateDailyMetricsCommand extends Command
{
    protected $signature = 'signals:aggregate-daily
                            {--date= : Specific date to aggregate (Y-m-d)}
                            {--from= : Start date for a range (Y-m-d)}
                            {--to= : End date for a range (Y-m-d)}
                            {--days=1 : Number of days to backfill from today}';

    protected $description = 'Aggregate daily Signals metrics from raw events and sessions';

    public function handle(SignalMetricsAggregator $aggregator): int
    {
        if ((bool) config('signals.features.owner.enabled', true) && OwnerContext::resolve() === null) {
            $owners = TrackedProperty::query()
                ->withoutOwnerScope()
                ->select(['owner_type', 'owner_id'])
                ->distinct()
                ->get();

            if ($owners->isEmpty()) {
                $this->runAggregation($aggregator);

                return self::SUCCESS;
            }

            foreach ($owners as $row) {
                $owner = $this->resolveOwnerFromRow($row);

                OwnerContext::withOwner($owner, function () use ($aggregator): void {
                    $this->runAggregation($aggregator);
                });
            }

            return self::SUCCESS;
        }

        $this->runAggregation($aggregator);

        return self::SUCCESS;
    }

    private function runAggregation(SignalMetricsAggregator $aggregator): void
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        $days = (int) $this->option('days');

        if (is_string($date) && $date !== '') {
            $day = Carbon::parse($date);
            $count = $aggregator->backfill($day->copy(), $day->copy());
            $this->info("Aggregated {$count} daily metric rows for {$date}.");

            return;
        }

        if (is_string($from) && is_string($to) && $from !== '' && $to !== '') {
            $count = $aggregator->backfill(Carbon::parse($from), Carbon::parse($to));
            $this->info("Aggregated {$count} daily metric rows from {$from} to {$to}.");

            return;
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays(max($days - 1, 0));
        $count = $aggregator->backfill($start, $end);

        $this->info("Aggregated {$count} daily metric rows from {$start->toDateString()} to {$end->toDateString()}.");
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
