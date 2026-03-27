<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SavedSignalReport;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalGoal;
use AIArmada\Signals\Models\TrackedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ConversionFunnelReportService
{
    public function __construct(
        private readonly SignalSegmentReportFilter $segmentReportFilter,
        private readonly SignalEventConditionQueryService $conditionQueryService,
        private readonly SignalEventConditionMatcher $conditionMatcher,
    ) {}

    /**
     * @return array{started:int,completed:int,paid:int,started_label:string,completed_label:string,paid_label:string,start_to_complete_rate:float,complete_to_paid_rate:float,overall_rate:float,start_drop_off:int,complete_drop_off:int,revenue_minor:int}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);
        $stages = $this->buildStages($context['tracked_property_id'], $context['from'], $context['until'], $context['signal_segment_id'], $context['saved_report']);

        if ($stages === []) {
            return [
                'started' => 0,
                'completed' => 0,
                'paid' => 0,
                'started_label' => 'Started',
                'completed_label' => 'Completed',
                'paid_label' => 'Completed',
                'start_to_complete_rate' => 0.0,
                'complete_to_paid_rate' => 0.0,
                'overall_rate' => 0.0,
                'start_drop_off' => 0,
                'complete_drop_off' => 0,
                'revenue_minor' => 0,
            ];
        }

        $entryStage = $stages[0];
        $middleStage = count($stages) > 1 ? $stages[count($stages) - 2] : $stages[0];
        $finalStage = $stages[count($stages) - 1];
        $secondStage = count($stages) > 1 ? $stages[1] : $stages[0];

        $started = $entryStage['count'];
        $completed = $middleStage['count'];
        $paid = $finalStage['count'];

        return [
            'started' => $started,
            'completed' => $completed,
            'paid' => $paid,
            'started_label' => $entryStage['label'],
            'completed_label' => $middleStage['label'],
            'paid_label' => $finalStage['label'],
            'start_to_complete_rate' => $this->rate($completed, $started),
            'complete_to_paid_rate' => $this->rate($paid, $completed),
            'overall_rate' => $this->rate($paid, $started),
            'start_drop_off' => max($started - $secondStage['count'], 0),
            'complete_drop_off' => max($completed - $paid, 0),
            'revenue_minor' => $finalStage['revenue_minor'],
        ];
    }

    /**
     * @return list<array{label:string,event_name:string,count:int,rate_from_previous:float,rate_from_start:float,drop_off:int,revenue_minor:int}>
     */
    public function stages(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array
    {
        $context = $this->resolveReportContext($trackedPropertyId, $from, $until, $signalSegmentId, $savedReportId);

        return $this->buildStages($context['tracked_property_id'], $context['from'], $context['until'], $context['signal_segment_id'], $context['saved_report']);
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
     * @return array<string, string>
     */
    public function getSavedReportOptions(): array
    {
        return SavedSignalReport::query()
            ->forOwner()
            ->where('report_type', 'conversion_funnel')
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return list<array{label:string,event_name:string,count:int,rate_from_previous:float,rate_from_start:float,drop_off:int,revenue_minor:int}>
     */
    private function buildStages(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, ?SavedSignalReport $savedReport): array
    {
        $steps = $this->resolveFunnelSteps($savedReport);

        if ($steps === []) {
            return [];
        }

        $progress = $this->calculateStageProgress($trackedPropertyId, $from, $until, $signalSegmentId, $steps, $savedReport);

        $entryCount = null;
        $previousCount = null;
        $stages = [];

        foreach ($steps as $index => $step) {
            $count = $progress[$index]['count'] ?? 0;
            $entryCount ??= $count;
            $nextCount = $progress[$index + 1]['count'] ?? null;

            $stages[] = [
                'label' => $step['label'],
                'event_name' => $step['event_name'],
                'count' => $count,
                'rate_from_previous' => $previousCount === null ? 100.0 : $this->rate($count, $previousCount),
                'rate_from_start' => $this->rate($count, $entryCount),
                'drop_off' => $nextCount === null ? 0 : max($count - $nextCount, 0),
                'revenue_minor' => $progress[$index]['revenue_minor'] ?? 0,
            ];

            $previousCount = $count;
        }

        return $stages;
    }

    /**
     * @return array{tracked_property_id:string|null,from:string|null,until:string|null,signal_segment_id:string|null,saved_report:SavedSignalReport|null}
     */
    private function resolveReportContext(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, ?string $savedReportId): array
    {
        $savedReport = $this->resolveSavedReport($savedReportId);
        $filters = $savedReport?->normalizedFilters() ?? [];

        return [
            'tracked_property_id' => $trackedPropertyId ?? $savedReport?->tracked_property_id,
            'from' => $from ?? ($filters['date_from'] ?? null),
            'until' => $until ?? ($filters['date_to'] ?? null),
            'signal_segment_id' => $signalSegmentId ?? $savedReport?->signal_segment_id,
            'saved_report' => $savedReport,
        ];
    }

    /**
     * @param  list<array{label:string,event_name:string,event_category:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}>  $steps
     * @return list<array{count:int,revenue_minor:int}>
     */
    private function calculateStageProgress(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, array $steps, ?SavedSignalReport $savedReport): array
    {
        $stepWindowMinutes = SavedSignalReportDefinition::stepWindowMinutes($savedReport?->normalizedSettings());
        $counts = array_fill(0, count($steps), 0);
        $revenues = array_fill(0, count($steps), 0);

        $events = $this->relevantEventsQuery($trackedPropertyId, $from, $until, $signalSegmentId, $steps)
            ->get();

        $eventsByActor = $events->groupBy(fn (SignalEvent $event): string => $this->actorKey($event));

        foreach ($eventsByActor as $actorEvents) {
            $this->applyActorProgression($actorEvents->values()->all(), $steps, $stepWindowMinutes, $counts, $revenues);
        }

        return array_map(
            static fn (int $count, int $revenueMinor): array => [
                'count' => $count,
                'revenue_minor' => $revenueMinor,
            ],
            $counts,
            $revenues,
        );
    }

    /**
     * @param  list<SignalEvent>  $actorEvents
     * @param  list<array{label:string,event_name:string,event_category:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}>  $steps
     * @param  array<int, int>  $counts
     * @param  array<int, int>  $revenues
     */
    private function applyActorProgression(array $actorEvents, array $steps, ?int $stepWindowMinutes, array &$counts, array &$revenues): void
    {
        $cursor = 0;
        $previousMatchedAt = null;

        foreach ($steps as $stageIndex => $step) {
            $matchedEvent = null;
            $latestAllowedAt = $previousMatchedAt instanceof CarbonImmutable && $stepWindowMinutes !== null
                ? $previousMatchedAt->addMinutes($stepWindowMinutes)
                : null;

            for ($position = $cursor; $position < count($actorEvents); $position++) {
                $event = $actorEvents[$position];

                if (! $this->matchesStep($event, $step)) {
                    continue;
                }

                if ($latestAllowedAt instanceof CarbonImmutable && $event->occurred_at->gt($latestAllowedAt)) {
                    break;
                }

                $matchedEvent = $event;
                $cursor = $position + 1;

                break;
            }

            if (! $matchedEvent instanceof SignalEvent) {
                break;
            }

            $counts[$stageIndex]++;
            $revenues[$stageIndex] += $stageIndex === array_key_last($steps)
                ? (int) $matchedEvent->revenue_minor
                : 0;
            $previousMatchedAt = $matchedEvent->occurred_at;
        }
    }

    /**
     * @param  list<array{label:string,event_name:string,event_category:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}>  $steps
     * @return Builder<SignalEvent>
     */
    private function relevantEventsQuery(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId, array $steps): Builder
    {
        return $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId)
            ->where(function (Builder $query) use ($steps): void {
                foreach ($steps as $index => $step) {
                    $method = $index === 0 ? 'where' : 'orWhere';

                    $query->{$method}(function (Builder $stepQuery) use ($step): void {
                        $stepQuery->where('event_name', $step['event_name']);

                        if ($step['event_category'] !== null) {
                            $stepQuery->where('event_category', $step['event_category']);
                        }

                        $this->conditionQueryService->apply($stepQuery, $step['conditions'], $step['condition_match_type']);
                    });
                }
            })
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * @param  array{label:string,event_name:string,event_category:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}  $step
     */
    private function matchesStep(SignalEvent $event, array $step): bool
    {
        if ($event->event_name !== $step['event_name']) {
            return false;
        }

        if ($step['event_category'] !== null && $event->event_category !== $step['event_category']) {
            return false;
        }

        return $this->conditionMatcher->matches($event, $step['conditions'], $step['condition_match_type']);
    }

    private function actorKey(SignalEvent $event): string
    {
        return $event->signal_identity_id
            ?? $event->signal_session_id
            ?? $event->id;
    }

    private function resolveSavedReport(?string $savedReportId): ?SavedSignalReport
    {
        if ($savedReportId === null || $savedReportId === '') {
            return null;
        }

        return SavedSignalReport::query()
            ->forOwner()
            ->where('report_type', 'conversion_funnel')
            ->where('is_active', true)
            ->whereKey($savedReportId)
            ->first();
    }

    /**
     * @return list<array{label:string,event_name:string,event_category:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}>
     */
    private function resolveFunnelSteps(?SavedSignalReport $savedReport): array
    {
        $steps = SavedSignalReportDefinition::funnelSteps($savedReport?->normalizedSettings());

        if ($steps !== []) {
            $resolvedSteps = $this->resolveConfiguredSteps($steps, $savedReport?->tracked_property_id);

            if ($resolvedSteps !== []) {
                return $resolvedSteps;
            }
        }

        $configuredSteps = config('signals.defaults.starter_funnel', []);

        if (! is_array($configuredSteps)) {
            return $this->defaultFunnelSteps();
        }

        $normalizedSteps = [];
        $configuredSteps = array_values($configuredSteps);
        $finalIndex = array_key_last($configuredSteps);

        foreach ($configuredSteps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $eventName = $step['event_name'] ?? null;

            if (! is_string($eventName) || $eventName === '') {
                $eventName = $index === $finalIndex
                    ? (string) config('signals.defaults.primary_outcome_event_name', 'conversion.completed')
                    : (string) config('signals.defaults.page_view_event_name', 'page_view');
            }

            $eventCategory = $step['event_category'] ?? null;

            $normalizedSteps[] = [
                'label' => is_string($step['label'] ?? null) && $step['label'] !== ''
                    ? $step['label']
                    : $this->defaultFunnelSteps()[$index]['label'] ?? 'Step ' . ($index + 1),
                'event_name' => $eventName,
                'event_category' => is_string($eventCategory) && $eventCategory !== ''
                    ? $eventCategory
                    : null,
                'condition_match_type' => 'all',
                'conditions' => null,
            ];
        }

        if (count($normalizedSteps) >= 2) {
            return $normalizedSteps;
        }

        return $this->defaultFunnelSteps();
    }

    /**
     * @return list<array{label:string,event_name:string,event_category:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}>
     */
    private function defaultFunnelSteps(): array
    {
        return [
            [
                'label' => 'Visited',
                'event_name' => (string) config('signals.defaults.page_view_event_name', 'page_view'),
                'event_category' => 'page_view',
                'condition_match_type' => 'all',
                'conditions' => null,
            ],
            [
                'label' => 'Explored Further',
                'event_name' => (string) config('signals.defaults.page_view_event_name', 'page_view'),
                'event_category' => 'page_view',
                'condition_match_type' => 'all',
                'conditions' => null,
            ],
            [
                'label' => 'Completed Outcome',
                'event_name' => (string) config('signals.defaults.primary_outcome_event_name', 'conversion.completed'),
                'event_category' => null,
                'condition_match_type' => 'all',
                'conditions' => null,
            ],
        ];
    }

    /**
     * @param  list<array{label:string,step_type:string,event_name:string|null,event_category:string|null,goal_slug:string|null,route_name:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}>  $steps
     * @return list<array{label:string,event_name:string,event_category:string|null,condition_match_type:string,conditions:array<int, array<string, mixed>>|null}>
     */
    private function resolveConfiguredSteps(array $steps, ?string $trackedPropertyId): array
    {
        $goalSlugs = array_values(array_unique(array_filter(array_map(
            static fn (array $step): ?string => $step['goal_slug'],
            $steps,
        ))));

        $goalsBySlug = SignalGoal::query()
            ->where('is_active', true)
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where(function (Builder $goalQuery) use ($trackedPropertyId): void {
                    $goalQuery->where('tracked_property_id', $trackedPropertyId)
                        ->orWhereNull('tracked_property_id');
                }),
            )
            ->when(
                $goalSlugs !== [],
                fn (Builder $query): Builder => $query->whereIn('slug', $goalSlugs)
            )
            ->get()
            ->keyBy('slug');

        $resolved = [];

        foreach ($steps as $step) {
            $goal = $step['goal_slug'] !== null ? $goalsBySlug->get($step['goal_slug']) : null;

            if ($goal instanceof SignalGoal) {
                $resolved[] = [
                    'label' => $step['label'],
                    'event_name' => $goal->event_name,
                    'event_category' => $goal->event_category,
                    'condition_match_type' => 'all',
                    'conditions' => $goal->conditions,
                ];

                continue;
            }

            if ($step['route_name'] !== null) {
                $routeCondition = app(SignalRouteCatalog::class)->conditionForRouteName($step['route_name']);

                if ($routeCondition !== null) {
                    $routeConditions = $step['conditions'] ?? [];
                    array_unshift($routeConditions, $routeCondition);

                    $resolved[] = [
                        'label' => $step['label'],
                        'event_name' => (string) config('signals.defaults.page_view_event_name', 'page_view'),
                        'event_category' => 'page_view',
                        'condition_match_type' => $step['condition_match_type'],
                        'conditions' => $routeConditions,
                    ];
                }

                continue;
            }

            if ($step['event_name'] === null) {
                continue;
            }

            $resolved[] = [
                'label' => $step['label'],
                'event_name' => $step['event_name'],
                'event_category' => $step['event_category'],
                'condition_match_type' => $step['condition_match_type'],
                'conditions' => $step['conditions'],
            ];
        }

        return $resolved;
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function baseQuery(?string $trackedPropertyId, ?string $from, ?string $until, ?string $signalSegmentId): Builder
    {
        return $this->segmentReportFilter->applyToEventQuery(SignalEvent::query(), $signalSegmentId)
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $trackedPropertyId)
            )
            ->when(
                filled($from),
                fn (Builder $query): Builder => $query->whereDate('occurred_at', '>=', (string) $from)
            )
            ->when(
                filled($until),
                fn (Builder $query): Builder => $query->whereDate('occurred_at', '<=', (string) $until)
            );
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator === 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }
}
