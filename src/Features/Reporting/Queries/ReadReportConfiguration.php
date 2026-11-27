<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Queries;

use AIArmada\Signals\Features\Ingestion\Api\TelemetryRead;
use AIArmada\Signals\Features\Reporting\Api\GoalDefinition;
use AIArmada\Signals\Features\Reporting\Api\ReportConfiguration;
use AIArmada\Signals\Features\Reporting\Api\SavedReportSnapshot;
use AIArmada\Signals\Features\Reporting\Api\SegmentDefinition;
use AIArmada\Signals\Features\Reporting\Api\SegmentOptions;
use AIArmada\Signals\Features\Reporting\Models\SavedSignalReport;
use AIArmada\Signals\Features\Reporting\Models\SignalGoal;
use AIArmada\Signals\Features\Reporting\Models\SignalSegment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class ReadReportConfiguration implements ReportConfiguration, SegmentOptions
{
    public function __construct(private TelemetryRead $telemetry) {}

    public function savedReport(string $type, ?string $id): ?SavedReportSnapshot
    {
        if ($id === null || $id === '') {
            return null;
        }

        $report = SavedSignalReport::query()->forOwner()
            ->where('report_type', $type)->where('is_active', true)->whereKey($id)->first();

        return $report === null ? null : new SavedReportSnapshot(
            $report->tracked_property_id,
            $report->signal_segment_id,
            $report->normalizedFilters(),
            $report->normalizedSettings(),
        );
    }

    public function savedReportOptions(string $type): array
    {
        return SavedSignalReport::query()->forOwner()->where('report_type', $type)
            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function getSegmentOptions(): array
    {
        return SignalSegment::query()->forOwner()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function segment(?string $id): ?SegmentDefinition
    {
        if ($id === null || $id === '') {
            return null;
        }

        $segment = SignalSegment::query()->forOwner()->where('is_active', true)->whereKey($id)->first();

        return $segment === null ? null : new SegmentDefinition($segment->conditions, $segment->match_type);
    }

    public function goals(?string $propertyId): array
    {
        return $this->definitions(SignalGoal::query()->where('is_active', true)
            ->when(filled($propertyId), fn (Builder $query): Builder => $query->where('tracked_property_id', $propertyId))
            ->orderBy('name')->get());
    }

    public function goalsBySlug(?string $propertyId, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $goals = SignalGoal::query()->where('is_active', true)->whereIn('slug', $slugs)
            ->when(filled($propertyId), fn (Builder $query): Builder => $query->where(function (Builder $query) use ($propertyId): void {
                $query->where('tracked_property_id', $propertyId)->orWhereNull('tracked_property_id');
            }))->orderBy('id')->get()
            ->sortBy(static function (SignalGoal $goal): int {
                if ($goal->owner_type === null && $goal->owner_id === null) {
                    return 0;
                }

                return $goal->tracked_property_id === null ? 1 : 2;
            })
            ->values();

        $result = [];

        foreach ($this->definitions($goals) as $goal) {
            $result[$goal->slug] = $goal;
        }

        return $result;
    }

    /** @param Collection<int, SignalGoal> $goals
     * @return list<GoalDefinition>
     */
    private function definitions(Collection $goals): array
    {
        $ids = $goals->pluck('tracked_property_id')->filter(static fn ($id): bool => is_string($id) && $id !== '')->unique()->values()->all();
        $properties = $this->telemetry->propertyDetails($ids);
        $result = [];

        foreach ($goals as $goal) {
            $property = $goal->tracked_property_id === null ? null : ($properties[$goal->tracked_property_id] ?? null);

            $result[] = new GoalDefinition(
                $goal->id,
                $goal->name,
                $goal->slug,
                $goal->goal_type,
                $goal->event_name,
                $goal->event_category,
                $goal->tracked_property_id,
                $property?->name,
                $property?->currency,
                $goal->conditions,
            );
        }

        return $result;
    }
}
