<?php

declare(strict_types=1);

namespace AIArmada\Signals;

use AIArmada\Signals\Console\Commands\AggregateDailyMetricsCommand;
use AIArmada\Signals\Console\Commands\ProcessSignalAlertsCommand;
use AIArmada\Signals\Services\CommerceSignalsRecorder;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use AIArmada\Signals\Services\SignalAlertEvaluator;
use AIArmada\Signals\Services\SignalMetricsAggregator;
use AIArmada\Signals\Services\SignalsDashboardService;
use AIArmada\Signals\Services\TrackedPropertyResolver;
use AIArmada\Signals\Support\CommerceSignalsIntegrationRegistrar;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class SignalsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('signals')
            ->hasConfigFile()
            ->runsMigrations()
            ->discoversMigrations()
            ->hasRoutes(['api'])
            ->hasCommand(AggregateDailyMetricsCommand::class)
            ->hasCommand(ProcessSignalAlertsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SignalsDashboardService::class);
        $this->app->singleton(SignalMetricsAggregator::class);
        $this->app->singleton(TrackedPropertyResolver::class);
        $this->app->singleton(CommerceSignalsRecorder::class);
        $this->app->singleton(SignalAlertEvaluator::class);
        $this->app->singleton(SignalAlertDispatcher::class);
    }

    public function packageBooted(): void
    {
        app(CommerceSignalsIntegrationRegistrar::class)->boot();
    }
}
