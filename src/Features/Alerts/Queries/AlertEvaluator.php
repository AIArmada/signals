<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Queries;

use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;

interface AlertEvaluator
{
    /** @return array{matched: bool, metric_value: float, context: array<string, mixed>} */
    public function evaluate(SignalAlertRule $rule, ?string $revenueCurrency = null): array;
}
