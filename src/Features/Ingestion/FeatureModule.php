<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion;

use AIArmada\Signals\Contracts\ReverseGeocoderContract;
use AIArmada\Signals\Contracts\SignalLocationResolverContract;
use AIArmada\Signals\Features\Ingestion\Actions\AdministerInteractionRule;
use AIArmada\Signals\Features\Ingestion\Actions\AdministerTrackedProperty;
use AIArmada\Signals\Features\Ingestion\Actions\CommerceSignalsRecorder;
use AIArmada\Signals\Features\Ingestion\Actions\IngestSignal;
use AIArmada\Signals\Features\Ingestion\Actions\ResolveBrowserProperty;
use AIArmada\Signals\Features\Ingestion\Actions\SignalLocationResolverPipeline;
use AIArmada\Signals\Features\Ingestion\Api\AcquisitionReportRead;
use AIArmada\Signals\Features\Ingestion\Api\BrowserInteractionRules;
use AIArmada\Signals\Features\Ingestion\Api\BrowserPropertyResolver;
use AIArmada\Signals\Features\Ingestion\Api\ContentPerformanceReportRead;
use AIArmada\Signals\Features\Ingestion\Api\ConversionFunnelReportRead;
use AIArmada\Signals\Features\Ingestion\Api\DailyTelemetryRead;
use AIArmada\Signals\Features\Ingestion\Api\DevicesReportRead;
use AIArmada\Signals\Features\Ingestion\Api\EventMetricQuery;
use AIArmada\Signals\Features\Ingestion\Api\EventPropertyLookup;
use AIArmada\Signals\Features\Ingestion\Api\GoalsReportRead;
use AIArmada\Signals\Features\Ingestion\Api\InteractionRuleAdministration;
use AIArmada\Signals\Features\Ingestion\Api\JourneyReportRead;
use AIArmada\Signals\Features\Ingestion\Api\LiveActivityReportRead;
use AIArmada\Signals\Features\Ingestion\Api\PropertyAdministration;
use AIArmada\Signals\Features\Ingestion\Api\RetentionReportRead;
use AIArmada\Signals\Features\Ingestion\Api\SignalIngestion;
use AIArmada\Signals\Features\Ingestion\Api\TelemetryRead;
use AIArmada\Signals\Features\Ingestion\Extensions\AcquisitionReportService;
use AIArmada\Signals\Features\Ingestion\Extensions\ContentPerformanceReportService;
use AIArmada\Signals\Features\Ingestion\Extensions\ConversionFunnelReportService;
use AIArmada\Signals\Features\Ingestion\Extensions\DevicesReportService;
use AIArmada\Signals\Features\Ingestion\Extensions\GoalsReportService;
use AIArmada\Signals\Features\Ingestion\Extensions\JourneyReportService;
use AIArmada\Signals\Features\Ingestion\Extensions\LiveActivityReportService;
use AIArmada\Signals\Features\Ingestion\Extensions\RetentionReportService;
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
        $app->bind(GoalsReportRead::class, GoalsReportService::class);
        $app->bind(RetentionReportRead::class, RetentionReportService::class);
        $app->bind(ConversionFunnelReportRead::class, ConversionFunnelReportService::class);
        $app->bind(DevicesReportRead::class, DevicesReportService::class);
        $app->bind(JourneyReportRead::class, JourneyReportService::class);
        $app->bind(LiveActivityReportRead::class, LiveActivityReportService::class);
        $app->bind(AcquisitionReportRead::class, AcquisitionReportService::class);
        $app->bind(ContentPerformanceReportRead::class, ContentPerformanceReportService::class);
        $app->bind(DailyTelemetryRead::class, ReadDailyTelemetry::class);
        $app->bind(TelemetryRead::class, ReadTelemetry::class);
        $app->bind(BrowserInteractionRules::class, ActiveBrowserInteractionRules::class);
        $app->bind(EventPropertyLookup::class, FindEventProperty::class);
        $app->bind(EventMetricQuery::class, CalculateEventMetric::class);
        $app->bind(InteractionRuleAdministration::class, AdministerInteractionRule::class);
        $app->bind(PropertyAdministration::class, AdministerTrackedProperty::class);
        $app->bind(SignalIngestion::class, IngestSignal::class);
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
