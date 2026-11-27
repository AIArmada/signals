<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Api;

interface ReportConfiguration
{
    public function savedReport(string $type, ?string $id): ?SavedReportSnapshot;

    /** @return array<string, string> */
    public function savedReportOptions(string $type): array;

    public function segment(?string $id): ?SegmentDefinition;

    /** @return list<GoalDefinition> */
    public function goals(?string $propertyId): array;

    /** @param list<string> $slugs
     * @return array<string, GoalDefinition>
     */
    public function goalsBySlug(?string $propertyId, array $slugs): array;
}
