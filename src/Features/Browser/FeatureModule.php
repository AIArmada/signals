<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Browser;

use AIArmada\Signals\Contracts\BrowserContextResolverInterface;
use AIArmada\Signals\Features\Browser\Api\TrackerMarkup;
use AIArmada\Signals\Features\Browser\EntryPoints\Http\Middleware\BootstrapSignalsBrowserContext;
use AIArmada\Signals\Features\Browser\EntryPoints\Http\SignalsBrowserContextResolver;
use AIArmada\Signals\Features\Browser\EntryPoints\Listeners\InjectSignalsTrackerIntoHtmlResponse;
use AIArmada\Signals\Features\Browser\Queries\SignalsTrackerRenderer;
use AIArmada\Signals\Platform\FeatureModule as FeatureModuleContract;
use AIArmada\Signals\Support\Http\SignalsBrowserContextManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->scoped(SignalsBrowserContextManager::class);
        $app->bind(TrackerMarkup::class, SignalsTrackerRenderer::class);
        $app->bind(BrowserContextResolverInterface::class, SignalsBrowserContextResolver::class);
    }

    public function boot(Application $app): void
    {
        $this->registerSignalsTrackerDirective($app);
        $this->registerBrowserMiddleware($app);
        $this->registerBrowserAutoInjection($app);
    }

    private function registerSignalsTrackerDirective(Application $app): void
    {
        if (! $app->bound('blade.compiler')) {
            return;
        }

        Blade::directive('signalsTracker', static function (?string $expression): string {
            $attributes = $expression !== null && $expression !== '' ? $expression : '[]';

            return sprintf(
                "<?php \$__signalsTrackerAttributes = %s; echo app('%s')->render(is_array(\$__signalsTrackerAttributes) ? \$__signalsTrackerAttributes : []); ?>",
                $attributes,
                addslashes(TrackerMarkup::class),
            );
        });
    }

    private function registerBrowserMiddleware(Application $app): void
    {
        if (! $app->bound('router')) {
            return;
        }

        $app['router']->aliasMiddleware('signals.browser', BootstrapSignalsBrowserContext::class);

        if (! (bool) config('signals.integrations.browser.enabled', false)) {
            return;
        }

        if (! (bool) config('signals.integrations.browser.auto_register_middleware', true)) {
            return;
        }

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup(
            (string) config('signals.integrations.browser.middleware_group', 'web'),
            BootstrapSignalsBrowserContext::class,
        );
    }

    private function registerBrowserAutoInjection(Application $app): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            app(InjectSignalsTrackerIntoHtmlResponse::class)->handle($event);
        });
    }
}
