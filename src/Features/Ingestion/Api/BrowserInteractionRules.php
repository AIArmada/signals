<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface BrowserInteractionRules
{
    /** @return list<array<string, mixed>> */
    public function forProperty(string $propertyId): array;
}
