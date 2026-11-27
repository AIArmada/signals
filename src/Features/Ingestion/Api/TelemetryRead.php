<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

use Carbon\CarbonImmutable;

interface TelemetryRead
{
    /** @return array<string, string> */
    public function propertyOptions(): array;

    /** @return array<string, string> */
    public function searchPropertyOptions(string $search): array;

    public function propertyLabel(string $id): ?string;

    /** @param list<string> $ids
     * @return array<string, PropertyDetails>
     */
    public function propertyDetails(array $ids): array;

    public function propertyCurrency(string $id): ?string;

    public function requirePropertyCurrency(string $id): string;

    public function propertyCurrencyForWrite(string $id): string;

    /** @return array{tracked_properties:int,identities:int} */
    public function counts(?string $propertyId, CarbonImmutable $startAt, CarbonImmutable $endAt): array;
}
