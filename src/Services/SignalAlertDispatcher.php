<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalAlertLog;
use AIArmada\Signals\Models\SignalAlertRule;

final class SignalAlertDispatcher
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(SignalAlertRule $rule, float $metricValue, array $context = []): SignalAlertLog
    {
        $log = SignalAlertLog::query()->create([
            'signal_alert_rule_id' => $rule->id,
            'tracked_property_id' => $rule->tracked_property_id,
            'metric_key' => $rule->metric_key,
            'operator' => $rule->operator,
            'metric_value' => $metricValue,
            'threshold_value' => $rule->threshold,
            'severity' => $rule->severity,
            'title' => $rule->name,
            'message' => $this->buildMessage($rule, $metricValue),
            'context' => $context,
            'channels_notified' => ['database'],
        ]);

        $rule->markTriggered();

        return $log;
    }

    private function buildMessage(SignalAlertRule $rule, float $metricValue): string
    {
        return sprintf(
            '%s triggered: %s %s %s over the last %d minute(s).',
            $rule->name,
            $rule->metric_key,
            $rule->operator,
            number_format($rule->threshold, 4, '.', ''),
            $rule->timeframe_minutes,
        ) . sprintf(' Current value: %s.', number_format($metricValue, 4, '.', ''));
    }
}
