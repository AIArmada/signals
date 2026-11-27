<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts;

use AIArmada\Signals\Features\Alerts\Actions\AdministerAlertRule;
use AIArmada\Signals\Features\Alerts\Actions\DeleteAlertLog;
use AIArmada\Signals\Features\Alerts\Actions\DetachAlertsFromProperty;
use AIArmada\Signals\Features\Alerts\Actions\EvaluateIngestedEvent;
use AIArmada\Signals\Features\Alerts\Actions\SetAlertReadState;
use AIArmada\Signals\Features\Alerts\Actions\SignalAlertDispatcher;
use AIArmada\Signals\Features\Alerts\Api\AlertEventEvaluation;
use AIArmada\Signals\Features\Alerts\Api\AlertLogAdministration;
use AIArmada\Signals\Features\Alerts\Api\AlertReadState;
use AIArmada\Signals\Features\Alerts\Api\AlertRuleAdministration;
use AIArmada\Signals\Features\Alerts\Api\AlertSummary;
use AIArmada\Signals\Features\Alerts\Api\DetachPropertyAlerts;
use AIArmada\Signals\Features\Alerts\Queries\AlertEvaluator;
use AIArmada\Signals\Features\Alerts\Queries\AlertSummaryQuery;
use AIArmada\Signals\Features\Alerts\Queries\SignalAlertEvaluator;
use AIArmada\Signals\Platform\FeatureModule as FeatureModuleContract;
use Illuminate\Contracts\Foundation\Application;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->bind(AlertRuleAdministration::class, AdministerAlertRule::class);
        $app->bind(AlertLogAdministration::class, DeleteAlertLog::class);
        $app->bind(AlertReadState::class, SetAlertReadState::class);
        $app->bind(DetachPropertyAlerts::class, DetachAlertsFromProperty::class);
        $app->bind(AlertSummary::class, AlertSummaryQuery::class);
        $app->bind(AlertEventEvaluation::class, EvaluateIngestedEvent::class);
        $app->singleton(AlertEvaluator::class, SignalAlertEvaluator::class);
        $app->singleton(SignalAlertDispatcher::class);
    }

    public function boot(Application $app): void {}
}
