<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface EventMetricQuery
{
    public function value(EventMetric $metric): float;
}
