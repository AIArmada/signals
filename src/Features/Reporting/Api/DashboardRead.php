<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Api;

use Carbon\CarbonImmutable;

interface DashboardRead
{
    /**
     * @return array{tracked_properties:int,identities:int,sessions:int,events:int,conversions:int,active_alert_rules:int,unread_alerts:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}
     */
    public function summary(?string $trackedPropertyId = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array;

    /**
     * @return list<array{date:string,events:int,conversions:int,revenue_minor?:int,currency?:string,revenue?:array<string, int>}>
     */
    public function trend(?string $trackedPropertyId = null, ?CarbonImmutable $startAt = null, ?CarbonImmutable $endAt = null): array;

    /** @return array<string, string> */
    public function availableReports(): array;

    /** @return array<string, mixed> */
    public function report(string $type, ?string $trackedPropertyId = null, ?string $from = null, ?string $until = null): array;
}
