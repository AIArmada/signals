<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Extensions;

use AIArmada\Signals\Contracts\ReportInterface;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Queries\SignalEventConditionQueryService;
use AIArmada\Signals\Features\Ingestion\Queries\SignalSegmentReportFilter;
use AIArmada\Signals\Features\Reporting\Api\GoalDefinition;
use AIArmada\Signals\Features\Reporting\Api\ReportConfiguration;
use AIArmada\Signals\Support\SignalCurrency;
use AIArmada\Signals\Support\SignalReportRevenue;
use Illuminate\Database\Eloquent\Builder;

final class GoalsReportService implements ReportInterface
{
    public function type(): string
    {
        return 'goals';
    }

    public function name(): string
    {
        return 'Goals';
    }

    public function __construct(
        private readonly SignalEventConditionQueryService $conditionQueryService,
        private readonly SignalSegmentReportFilter $segmentReportFilter,
        private readonly ReportConfiguration $configuration,
    ) {}

    /**
     * @return array{goals:int,goal_hits:int,visitors:int,avg_goal_rate:float,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array
    {
        return $this->report($trackedPropertyId, $from, $until, $signalSegmentId)['summary'];
    }

    /**
     * Build the summary and rows from a single goal query.
     *
     * @return array{
     *     summary: array{goals:int,goal_hits:int,visitors:int,avg_goal_rate:float,revenue_minor?:int,currency?:string,revenue?:array<string, int>},
     *     rows: list<array{id:string,name:string,goal_type:string,event_name:string,event_category:?string,tracked_property_name:?string,goal_hits:int,visitors:int,goal_rate:float,last_hit_at:?string,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>
     * }
     */
    public function report(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array
    {
        $rows = $this->rows($trackedPropertyId, $from, $until, $signalSegmentId);

        return [
            'summary' => $this->summarize($rows, $trackedPropertyId),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array{id:string,name:string,goal_type:string,event_name:string,event_category:?string,tracked_property_name:?string,goal_hits:int,visitors:int,goal_rate:float,last_hit_at:?string,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>  $rows
     * @return array{goals:int,goal_hits:int,visitors:int,avg_goal_rate:float,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    private function summarize(array $rows, ?string $trackedPropertyId): array
    {
        $revenue = [];

        foreach ($rows as $row) {
            if (is_array($row['revenue'] ?? null)) {
                foreach ($row['revenue'] as $currency => $amount) {
                    $revenue[$currency] = ($revenue[$currency] ?? 0) + (int) $amount;
                }

                continue;
            }

            $currency = $row['currency'] ?? SignalCurrency::default();
            $revenue[$currency] = ($revenue[$currency] ?? 0) + (int) ($row['revenue_minor'] ?? 0);
        }

        ksort($revenue);

        return [
            'goals' => count($rows),
            'goal_hits' => array_sum(array_column($rows, 'goal_hits')),
            'visitors' => array_sum(array_column($rows, 'visitors')),
            'avg_goal_rate' => round(count($rows) > 0 ? array_sum(array_column($rows, 'goal_rate')) / count($rows) : 0, 2),
            ...SignalReportRevenue::shape($trackedPropertyId, $revenue),
        ];
    }

    /**
     * @return list<array{id:string,name:string,goal_type:string,event_name:string,event_category:?string,tracked_property_name:?string,goal_hits:int,visitors:int,goal_rate:float,last_hit_at:?string,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>
     */
    public function rows(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array
    {
        return collect($this->configuration->goals($trackedPropertyId))
            ->map(function (GoalDefinition $goal) use ($from, $until, $signalSegmentId): array {
                $query = $this->goalEventQuery($goal, $from, $until, $signalSegmentId);
                $goalHits = (int) (clone $query)->count();
                $visitors = $this->distinctActorCount(clone $query);
                $propertyCurrency = filled($goal->trackedPropertyId)
                    ? SignalCurrency::code($goal->currency)
                    : null;

                return [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'goal_type' => $goal->goalType,
                    'event_name' => $goal->eventName,
                    'event_category' => $goal->eventCategory,
                    'tracked_property_name' => $goal->trackedPropertyName,
                    'goal_hits' => $goalHits,
                    'visitors' => $visitors,
                    ...SignalReportRevenue::shape($goal->trackedPropertyId, SignalReportRevenue::byCurrency(clone $query), $propertyCurrency),
                    'goal_rate' => $visitors > 0 ? round(($goalHits / $visitors) * 100, 2) : 0.0,
                    'last_hit_at' => (clone $query)->max('occurred_at'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function goalEventQuery(GoalDefinition $goal, ?string $from, ?string $until, ?string $signalSegmentId): Builder
    {
        $query = $this->segmentReportFilter->applyToEventQuery(SignalEvent::query(), $signalSegmentId)
            ->where('event_name', $goal->eventName)
            ->when(
                filled($goal->eventCategory),
                fn (Builder $query): Builder => $query->where('event_category', $goal->eventCategory)
            )
            ->when(
                filled($goal->trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $goal->trackedPropertyId)
            )
            ->when(
                filled($from),
                fn (Builder $query): Builder => $query->whereDate('occurred_at', '>=', (string) $from)
            )
            ->when(
                filled($until),
                fn (Builder $query): Builder => $query->whereDate('occurred_at', '<=', (string) $until)
            );

        return $this->conditionQueryService->apply($query, $goal->conditions, 'all');
    }

    /**
     * @param  Builder<SignalEvent>  $query
     */
    private function distinctActorCount(Builder $query): int
    {
        return (int) $query
            ->selectRaw('COUNT(DISTINCT COALESCE(signal_identity_id, signal_session_id, id)) as aggregate')
            ->value('aggregate');
    }
}
