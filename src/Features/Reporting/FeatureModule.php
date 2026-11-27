<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting;

use AIArmada\Signals\Contracts\ReportInterface;
use AIArmada\Signals\Features\Reporting\Actions\AdministerGoal;
use AIArmada\Signals\Features\Reporting\Actions\AdministerSavedReport;
use AIArmada\Signals\Features\Reporting\Actions\AdministerSegment;
use AIArmada\Signals\Features\Reporting\Actions\AggregateDailyMetrics;
use AIArmada\Signals\Features\Reporting\Actions\DetachReportsFromProperty;
use AIArmada\Signals\Features\Reporting\Api\DashboardRead;
use AIArmada\Signals\Features\Reporting\Api\DetachPropertyReports;
use AIArmada\Signals\Features\Reporting\Api\GoalAdministration;
use AIArmada\Signals\Features\Reporting\Api\ReportConfiguration;
use AIArmada\Signals\Features\Reporting\Api\SavedReportAdministration;
use AIArmada\Signals\Features\Reporting\Api\SegmentAdministration;
use AIArmada\Signals\Features\Reporting\Api\SegmentOptions;
use AIArmada\Signals\Features\Reporting\Queries\ReadReportConfiguration;
use AIArmada\Signals\Features\Reporting\Queries\ReportRegistry;
use AIArmada\Signals\Features\Reporting\Queries\SignalsDashboardService;
use AIArmada\Signals\Platform\ExtensionRegistry;
use AIArmada\Signals\Platform\FeatureModule as FeatureModuleContract;
use Illuminate\Contracts\Foundation\Application;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->bind(SavedReportAdministration::class, AdministerSavedReport::class);
        $app->bind(SegmentAdministration::class, AdministerSegment::class);
        $app->bind(GoalAdministration::class, AdministerGoal::class);
        $app->singleton(DashboardRead::class, SignalsDashboardService::class);
        $app->bind(SegmentOptions::class, ReadReportConfiguration::class);
        $app->bind(ReportConfiguration::class, ReadReportConfiguration::class);
        $app->singleton(AggregateDailyMetrics::class);
        $app->bind(DetachPropertyReports::class, DetachReportsFromProperty::class);
        $app->singleton(ReportRegistry::class, function (Application $app): ReportRegistry {
            $registry = new ReportRegistry;

            foreach ($app->make(ExtensionRegistry::class)->resolve('signals.reports', ReportInterface::class) as $report) {
                if ($report instanceof ReportInterface) {
                    $registry->register($report);
                }
            }

            return $registry;
        });
    }

    public function boot(Application $app): void {}
}
