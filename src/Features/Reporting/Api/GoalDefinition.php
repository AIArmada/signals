<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Api;

final readonly class GoalDefinition
{
    /** @param array<int, array<string, mixed>>|null $conditions */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $goalType,
        public string $eventName,
        public ?string $eventCategory,
        public ?string $trackedPropertyId,
        public ?string $trackedPropertyName,
        public ?string $currency,
        public ?array $conditions,
    ) {}
}
