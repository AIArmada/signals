<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface ContentPerformanceReportRead
{
    /**
     * @return array{paths:int,views:int,conversions:int,avg_conversion_rate:float,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array;

    public function getBreakdownLabel(?string $savedReportId = null): string;
}
