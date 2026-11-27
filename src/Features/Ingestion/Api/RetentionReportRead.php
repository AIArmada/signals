<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface RetentionReportRead
{
    /**
     * Build the summary and cohort rows from a single cohort query.
     *
     * @return array{
     *     summary: array{cohorts:int,identities:int,windows:list<array{days:int,retained:int,avg_retention_rate:float}>},
     *     rows: list<array{cohort_date:string,cohort_size:int,windows:list<array{days:int,retained:int,retention_rate:float}>}>
     * }
     */
    public function report(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array;
}
