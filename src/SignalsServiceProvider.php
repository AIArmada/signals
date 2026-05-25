<?php

declare(strict_types=1);

namespace AIArmada\Signals;

use AIArmada\Signals\Console\Commands\AggregateDailyMetricsCommand;
use AIArmada\Signals\Console\Commands\ProcessSignalAlertsCommand;
use AIArmada\Signals\Contracts\SignalLocationResolverContract;
use AIArmada\Signals\Services\CommerceSignalsRecorder;
use AIArmada\Signals\Services\Geocoders\NominatimGeocoder;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use AIArmada\Signals\Services\SignalAlertEvaluator;
use AIArmada\Signals\Services\SignalLocationResolverPipeline;
use AIArmada\Signals\Services\SignalMetricsAggregator;
use AIArmada\Signals\Services\SignalsDashboardService;
use AIArmada\Signals\Services\TrackedPropertyResolver;
use AIArmada\Signals\Support\Browser\InjectSignalsTrackerIntoHtmlResponse;
use AIArmada\Signals\Support\Browser\SignalsBrowserContextManager;
use AIArmada\Signals\Support\Browser\SignalsTrackerRenderer;
use AIArmada\Signals\Support\CommerceSignalsIntegrationRegistrar;
use AIArmada\Signals\Support\Http\Middleware\BootstrapSignalsBrowserContext;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(SignalsBrowserContextManager::class);
        $this->app->bind(SignalsTrackerRenderer::class);
        $this->app->bind(InjectSignalsTrackerIntoHtmlResponse::class);
        $this->app->singleton(SignalLocationResolverPipeline::class, function ($app): SignalLocationResolverPipeline {
            $pipeline = new SignalLocationResolverPipeline;
            $pipeline->registerGeocoder($app->make(NominatimGeocoder::class));

            if ($app->bound(SignalLocationResolverContract::class)) {
                $pipeline->registerResolver($app->make(SignalLocationResolverContract::class));
            }

            return $pipeline;
        });
    }

    public function packageBooted(): void
    {
        $this->registerSignalsTrackerDirective();
        $this->registerBrowserMiddleware();
        $this->registerBrowserAutoInjection();
        app(CommerceSignalsIntegrationRegistrar::class)->boot();
    }

    private function registerSignalsTrackerDirective(): void
    {
        if (! $this->app->bound('blade.compiler')) {
            return;
        }

        Blade::directive('signalsTracker', static function (?string $expression): string {
            $attributes = $expression !== null && $expression !== '' ? $expression : '[]';

            return sprintf(
                "<?php \$__signalsTrackerAttributes = %s; echo app('%s')->render(is_array(\$__signalsTrackerAttributes) ? \$__signalsTrackerAttributes : []); ?>",
                $attributes,
                addslashes(SignalsTrackerRenderer::class),
            );
        });
    }

    private function registerBrowserMiddleware(): void
    {
        if (! $this->app->bound('router')) {
            return;
        }

        $this->app['router']->aliasMiddleware('signals.browser', BootstrapSignalsBrowserContext::class);

        if (! (bool) config('signals.integrations.browser.enabled', false)) {
            return;
        }

        if (! (bool) config('signals.integrations.browser.auto_register_middleware', true)) {
            return;
        }

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup(
            (string) config('signals.integrations.browser.middleware_group', 'web'),
            BootstrapSignalsBrowserContext::class,
        );
    }

    private function registerBrowserAutoInjection(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            app(InjectSignalsTrackerIntoHtmlResponse::class)->handle($event);
        });
    }
}
