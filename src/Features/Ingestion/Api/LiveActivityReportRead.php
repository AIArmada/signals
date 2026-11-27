<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface LiveActivityReportRead
{
    /**
     * @return array{events:int,page_views:int,conversions:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array;
}
