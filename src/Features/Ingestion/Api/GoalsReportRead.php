<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface GoalsReportRead
{
    /**
     * Build the summary and rows from a single goal query.
     *
     * @return array{
     *     summary: array{goals:int,goal_hits:int,visitors:int,avg_goal_rate:float,revenue_minor?:int,currency?:string,revenue?:array<string, int>},
     *     rows: list<array{id:string,name:string,goal_type:string,event_name:string,event_category:?string,tracked_property_name:?string,goal_hits:int,visitors:int,goal_rate:float,last_hit_at:?string,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>
     * }
     */
    public function report(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null, ?string $signalSegmentId = null): array;
}
