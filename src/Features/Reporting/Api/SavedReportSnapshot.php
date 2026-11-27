<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Api;

final readonly class SavedReportSnapshot
{
    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public ?string $trackedPropertyId,
        public ?string $segmentId,
        public array $filters,
        public array $settings,
    ) {}
}
