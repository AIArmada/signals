<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Api;

interface SegmentOptions
{
    /** @return array<string, string> */
    public function getSegmentOptions(): array;
}
