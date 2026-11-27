<?php

declare(strict_types=1);

namespace AIArmada\Signals\Data;

final readonly class AlertDeliveryMessage
{
    /** @param array<string, mixed>|null $context */
    public function __construct(
        public string $ruleId,
        public string $logId,
        public string $title,
        public string $severity,
        public string $metricKey,
        public ?string $message,
        public ?array $context,
    ) {}
}
