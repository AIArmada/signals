<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface ConversionFunnelReportRead
{
    /**
     * Build the summary and stage rows from a single funnel query.
     *
     * @return array{
     *     summary: array{started:int,completed:int,paid:int,started_label:string,completed_label:string,paid_label:string,start_to_complete_rate:float,complete_to_paid_rate:float,overall_rate:float,start_drop_off:int,complete_drop_off:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>},
     *     stages: list<array{label:string,event_name:string,count:int,rate_from_previous:float,rate_from_start:float,drop_off:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>
     * }
     */
    public function report(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null, ?string $savedReportId = null): array;
}
