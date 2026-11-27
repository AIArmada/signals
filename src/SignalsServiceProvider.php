<?php

declare(strict_types=1);

namespace AIArmada\Signals;

use AIArmada\Signals\Contracts\AlertTransport;
use AIArmada\Signals\Contracts\ReverseGeocoderContract;
use AIArmada\Signals\Contracts\UserAgentParser;
use AIArmada\Signals\Drivers\DefaultAlertTransport;
use AIArmada\Signals\Drivers\MatomoUserAgentParser;
use AIArmada\Signals\Drivers\NominatimGeocoder;
use AIArmada\Signals\Features\Alerts\EntryPoints\Console\ProcessSignalAlertsCommand;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertDelivery;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\SignalIdentity;
use AIArmada\Signals\Features\Ingestion\Models\SignalInteractionRule;
use AIArmada\Signals\Features\Ingestion\Models\SignalSession;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use AIArmada\Signals\Features\Reporting\EntryPoints\Console\AggregateDailyMetricsCommand;
use AIArmada\Signals\Features\Reporting\Models\SavedSignalReport;
use AIArmada\Signals\Features\Reporting\Models\SignalDailyMetric;
use AIArmada\Signals\Features\Reporting\Models\SignalGoal;
use AIArmada\Signals\Features\Reporting\Models\SignalSegment;
use AIArmada\Signals\Platform\ExtensionRegistry;
use AIArmada\Signals\Platform\FeatureCatalog;
use AIArmada\Signals\Platform\FeatureModule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
            ->hasCommand(AggregateDailyMetricsCommand::class)
            ->hasCommand(ProcessSignalAlertsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(AlertTransport::class, DefaultAlertTransport::class);
        $this->app->bind(ReverseGeocoderContract::class, NominatimGeocoder::class);
        $this->app->bind(UserAgentParser::class, MatomoUserAgentParser::class);

        $catalog = FeatureCatalog::load(dirname(__DIR__), __NAMESPACE__);
        $this->app->singleton(ExtensionRegistry::class, fn (Application $app): ExtensionRegistry => new ExtensionRegistry($app, $catalog['extensions']));

        foreach (array_keys($catalog['features']) as $name) {
            $module = $this->app->make(__NAMESPACE__ . '\\Features\\' . $name . '\\FeatureModule');
            if ($module instanceof FeatureModule) {
                $module->register($this->app);
            }
        }

    }

    public function packageBooted(): void
    {
        foreach (array_keys(FeatureCatalog::load(dirname(__DIR__), __NAMESPACE__)['features']) as $name) {
            $module = $this->app->make(__NAMESPACE__ . '\\Features\\' . $name . '\\FeatureModule');
            if ($module instanceof FeatureModule) {
                $module->boot($this->app);
            }
        }

        $this->registerIngestionRateLimiter();
        $this->registerMorphMap();
        $this->loadRoutesFrom(__DIR__ . '/Features/Browser/Routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/Features/Ingestion/Routes/api.php');
    }

    private function registerIngestionRateLimiter(): void
    {
        RateLimiter::for('signals-collect', static function (Request $request): array {
            $limit = max(1, (int) config('signals.ingestion.browser.rate_limit_per_minute', 120));
            $propertyLimit = max(1, (int) config('signals.ingestion.browser.property_rate_limit_per_minute', 120));

            return [
                Limit::perMinute($limit)->by('signals:collect:ip:' . ($request->ip() ?? 'unknown')),
                Limit::perMinute($propertyLimit)->by('signals:collect:write-key:' . hash('sha256', (string) $request->input('write_key', ''))),
            ];
        });
    }

    private function registerMorphMap(): void
    {
        Relation::morphMap([
            'tracked_property' => TrackedProperty::class,
            'signal_session' => SignalSession::class,
            'signal_event' => SignalEvent::class,
            'signal_identity' => SignalIdentity::class,
            'signal_goal' => SignalGoal::class,
            'signal_daily_metric' => SignalDailyMetric::class,
            'signal_interaction_rule' => SignalInteractionRule::class,
            'signal_alert_rule' => SignalAlertRule::class,
            'signal_alert_log' => SignalAlertLog::class,
            'signal_alert_delivery' => SignalAlertDelivery::class,
            'signal_segment' => SignalSegment::class,
            'saved_signal_report' => SavedSignalReport::class,
        ]);
    }
}
