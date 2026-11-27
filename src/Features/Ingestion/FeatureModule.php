<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion;

use AIArmada\Signals\Contracts\ReverseGeocoderContract;
use AIArmada\Signals\Contracts\SignalLocationResolverContract;
use AIArmada\Signals\Features\Ingestion\Actions\AdministerInteractionRule;
use AIArmada\Signals\Features\Ingestion\Actions\AdministerTrackedProperty;
use AIArmada\Signals\Features\Ingestion\Actions\CommerceSignalsRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\ResolveBrowserProperty;
use AIArmada\Signals\Features\Ingestion\Actions\SignalLocationResolverPipeline;
use AIArmada\Signals\Features\Ingestion\Api\BrowserInteractionRules;
use AIArmada\Signals\Features\Ingestion\Api\BrowserPropertyResolver;
use AIArmada\Signals\Features\Ingestion\Api\DailyTelemetryRead;
use AIArmada\Signals\Features\Ingestion\Api\EventMetricQuery;
use AIArmada\Signals\Features\Ingestion\Api\EventPropertyLookup;
use AIArmada\Signals\Features\Ingestion\Api\InteractionRuleAdministration;
use AIArmada\Signals\Features\Ingestion\Api\PropertyAdministration;
use AIArmada\Signals\Features\Ingestion\Api\TelemetryRead;
use AIArmada\Signals\Features\Ingestion\Queries\ActiveBrowserInteractionRules;
use AIArmada\Signals\Features\Ingestion\Queries\CalculateEventMetric;
use AIArmada\Signals\Features\Ingestion\Queries\FindEventProperty;
use AIArmada\Signals\Features\Ingestion\Queries\ReadDailyTelemetry;
use AIArmada\Signals\Features\Ingestion\Queries\ReadTelemetry;
use AIArmada\Signals\Features\Ingestion\Support\CommerceSignalsIntegrationRegistrar;
use AIArmada\Signals\Platform\FeatureModule as FeatureModuleContract;
use Illuminate\Contracts\Foundation\Application;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->bind(DailyTelemetryRead::class, ReadDailyTelemetry::class);
        $app->bind(TelemetryRead::class, ReadTelemetry::class);
        $app->bind(BrowserInteractionRules::class, ActiveBrowserInteractionRules::class);
        $app->bind(EventPropertyLookup::class, FindEventProperty::class);
        $app->bind(EventMetricQuery::class, CalculateEventMetric::class);
        $app->bind(InteractionRuleAdministration::class, AdministerInteractionRule::class);
        $app->bind(PropertyAdministration::class, AdministerTrackedProperty::class);
        $app->singleton(CommerceSignalsRecorder::class);
        $app->bind(BrowserPropertyResolver::class, ResolveBrowserProperty::class);
        $app->singleton(SignalLocationResolverPipeline::class, function ($app): SignalLocationResolverPipeline {
            $pipeline = new SignalLocationResolverPipeline;
            $pipeline->registerGeocoder($app->make(ReverseGeocoderContract::class));

            if ($app->bound(SignalLocationResolverContract::class)) {
                $pipeline->registerResolver($app->make(SignalLocationResolverContract::class));
            }

            return $pipeline;
        });
    }

    public function boot(Application $app): void
    {
        $app->make(CommerceSignalsIntegrationRegistrar::class)->boot();
    }
}
