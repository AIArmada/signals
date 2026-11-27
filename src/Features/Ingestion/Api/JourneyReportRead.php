<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface JourneyReportRead
{
    /**
     * @return array{sessions:int,unique_entry_paths:int,unique_exit_paths:int,bounced_sessions:int,avg_duration_seconds:float}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array;

    public function getBreakdownLabel(?string $savedReportId = null): string;
}
