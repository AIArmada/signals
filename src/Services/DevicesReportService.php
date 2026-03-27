<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

final class DevicesReportService
{
    public function __construct(private readonly SignalSegmentReportFilter $segmentReportFilter) {}

    /**
     * @return Builder<SignalSession>
     */
    public function getDeviceTypeQuery(
        ?string $trackedPropertyId = null,
        ?string $from = null,
        ?string $until = null,
        ?string $signalSegmentId = null,
        bool $excludeBots = true,
    ): Builder {
        return $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId, $excludeBots)
            ->whereNotNull('device_type')
            ->selectRaw('device_type AS id')
            ->selectRaw('device_type')
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COUNT(DISTINCT signal_identity_id) as visitors')
            ->groupBy('device_type')
            ->orderByDesc('sessions');
    }

    /**
     * @return Builder<SignalSession>
     */
    public function getBrowserQuery(
        ?string $trackedPropertyId = null,
        ?string $from = null,
        ?string $until = null,
        ?string $signalSegmentId = null,
        bool $excludeBots = true,
    ): Builder {
        return $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId, $excludeBots)
            ->whereNotNull('browser')
            ->selectRaw('browser AS id')
            ->selectRaw('browser')
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COUNT(DISTINCT signal_identity_id) as visitors')
            ->groupBy('browser')
            ->orderByDesc('sessions');
    }

    /**
     * @return Builder<SignalSession>
     */
    public function getOsQuery(
        ?string $trackedPropertyId = null,
        ?string $from = null,
        ?string $until = null,
        ?string $signalSegmentId = null,
        bool $excludeBots = true,
    ): Builder {
        return $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId, $excludeBots)
            ->whereNotNull('os')
            ->selectRaw('os AS id')
            ->selectRaw('os')
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COUNT(DISTINCT signal_identity_id) as visitors')
            ->groupBy('os')
            ->orderByDesc('sessions');
    }

    /**
     * @return Builder<SignalSession>
     */
    public function getBrandModelQuery(
        ?string $trackedPropertyId = null,
        ?string $from = null,
        ?string $until = null,
        ?string $signalSegmentId = null,
        bool $excludeBots = true,
    ): Builder {
        return $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId, $excludeBots)
            ->whereNotNull('device_brand')
            ->selectRaw('device_brand AS id')
            ->selectRaw('device_brand')
            ->selectRaw('MAX(device_model) as device_model')
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COUNT(DISTINCT signal_identity_id) as visitors')
            ->groupBy('device_brand')
            ->orderByDesc('sessions');
    }

    /**
     * @return array{sessions:int,browsers:int,operating_systems:int,brands:int,bots:int}
     */
    public function summary(
        ?string $trackedPropertyId = null,
        ?string $from = null,
        ?string $until = null,
        ?string $signalSegmentId = null,
    ): array {
        $base = $this->baseQuery($trackedPropertyId, $from, $until, $signalSegmentId, false);

        return [
            'sessions' => (int) (clone $base)->count(),
            'browsers' => (int) (clone $base)->whereNotNull('browser')->distinct()->count('browser'),
            'operating_systems' => (int) (clone $base)->whereNotNull('os')->distinct()->count('os'),
            'brands' => (int) (clone $base)->whereNotNull('device_brand')->distinct()->count('device_brand'),
            'bots' => (int) (clone $base)->where('is_bot', true)->count(),
        ];
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
     * @return Builder<SignalSession>
     */
    private function baseQuery(
        ?string $trackedPropertyId,
        ?string $from,
        ?string $until,
        ?string $signalSegmentId,
        bool $excludeBots,
    ): Builder {
        return $this->segmentReportFilter->applyToSessionQuery(SignalSession::query(), $signalSegmentId)
            ->when(
                filled($trackedPropertyId),
                fn (Builder $query): Builder => $query->where('tracked_property_id', $trackedPropertyId)
            )
            ->when(
                filled($from),
                fn (Builder $query): Builder => $query->whereDate('started_at', '>=', (string) $from)
            )
            ->when(
                filled($until),
                fn (Builder $query): Builder => $query->whereDate('started_at', '<=', (string) $until)
            )
            ->when(
                $excludeBots,
                fn (Builder $query): Builder => $query->where('is_bot', false)
            );
    }
}
