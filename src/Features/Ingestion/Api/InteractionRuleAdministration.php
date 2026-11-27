<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface InteractionRuleAdministration
{
    /** @param array<string, mixed> $data */
    public function create(array $data): string;

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void;

    /** @param list<array<string, mixed>> $candidates */
    public function createScanned(string $propertyId, string $triggerType, string $eventName, ?string $eventCategory, bool $isActive, array $candidates): int;

    public function delete(string $id): bool;
}
