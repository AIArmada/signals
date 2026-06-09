<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

interface ReportInterface
{
    /**
     * Get the report type identifier.
     */
    public function type(): string;

    /**
     * Get the human-readable report name.
     */
    public function name(): string;

    /**
     * Get a summary of report data.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $trackedPropertyId = null, ?string $from = null, ?string $until = null): array;
}
