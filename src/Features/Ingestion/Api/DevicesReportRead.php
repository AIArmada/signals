<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface DevicesReportRead
{
    /**
     * @return array{sessions:int,browsers:int,operating_systems:int,brands:int,bots:int}
     */
    public function summary(
        ?string $trackedPropertyId = null,
        ?string $from = null,
        ?string $until = null,
        ?string $signalSegmentId = null,
    ): array;
}
