<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalGoal;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Support\SignalCurrency;
use AIArmada\Signals\Support\SignalReportRevenue;
use Illuminate\Database\Eloquent\Builder;

final class GoalsReportService
{
    public function __construct(
        private readonly SignalEventConditionQueryService $conditionQueryService,
        private readonly SignalSegmentReportFilter $segmentReportFilter,
    ) {}

    /**
     * @return array{goals:int,goal_hits:int,visitors:int,avg_goal_rate:float,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array
    {
        $rows = $this->rows($trackedPropertyId, $from, $until, $signalSegmentId);
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
        return SignalGoal::query()
            ->with('trackedProperty')
            ->where('is_active', true)
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $trackedPropertyId)
            )
            ->orderBy('name')
            ->get()
            ->map(function (SignalGoal $goal) use ($from, $until, $signalSegmentId): array {
                $query = $this->goalEventQuery($goal, $from, $until, $signalSegmentId);
                $goalHits = (int) (clone $query)->count();
                $visitors = $this->distinctActorCount(clone $query);
                $propertyCurrency = filled($goal->tracked_property_id)
                    ? SignalCurrency::code($goal->trackedProperty?->currency)
                    : null;

                return [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'goal_type' => $goal->goal_type,
                    'event_name' => $goal->event_name,
                    'event_category' => $goal->event_category,
                    'tracked_property_name' => $goal->trackedProperty?->name,
                    'goal_hits' => $goalHits,
                    'visitors' => $visitors,
                    ...SignalReportRevenue::shape($goal->tracked_property_id, SignalReportRevenue::byCurrency(clone $query), $propertyCurrency),
                    'goal_rate' => $visitors > 0 ? round(($goalHits / $visitors) * 100, 2) : 0.0,
                    'last_hit_at' => (clone $query)->max('occurred_at'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getTrackedPropertyOptions(): array
    {
        return TrackedProperty::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function goalEventQuery(SignalGoal $goal, ?string $from, ?string $until, ?string $signalSegmentId): Builder
    {
        $query = $this->segmentReportFilter->applyToEventQuery(SignalEvent::query(), $signalSegmentId)
            ->where('event_name', $goal->event_name)
            ->when(
                filled($goal->event_category),
                fn (Builder $query): Builder => $query->where('event_category', $goal->event_category)
            )
            ->when(
                filled($goal->tracked_property_id),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $goal->tracked_property_id)
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
