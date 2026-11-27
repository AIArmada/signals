<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface AcquisitionReportRead
{
    /**
     * @return array{attributed_events:int,visitors:int,conversions:int,campaigns:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $attributionModel = null, ?string $savedReportId = null): array;
}
