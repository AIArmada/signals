<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Api;

final readonly class SegmentDefinition
{
    /** @param array<int, array<string, mixed>>|null $conditions */
    public function __construct(
        public ?array $conditions,
        public string $matchType,
    ) {}
}
