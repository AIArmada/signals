---
title: Reporting And Alerts
---

# Reporting And Alerts

Signals owns generic analytics and alerting for the Commerce packages.

## Reporting surfaces

Signals ships dedicated services for:

- page views
- conversion funnels
- acquisition
- journeys
- retention
- content performance
- goals
- live activity
- devices and technology

These services all operate on the same owner-scoped Signals data and can be reused outside Filament.

## Saved reports and segments

- `SavedSignalReport` stores reusable report state for supported report types
- `SignalSegment` stores reusable filter conditions that can be applied across reports and alerts
- both remain owner-scoped and should be resolved inside the current owner context

## Route-aware report filters

`SignalRouteCatalog` can convert named routes into path conditions that are safe to reuse in reports or segments:

```php
use AIArmada\Signals\Services\SignalRouteCatalog;

$condition = app(SignalRouteCatalog::class)->conditionForRouteName('pricing.show');

// ['field' => 'path', 'operator' => 'equals', 'value' => '/pricing']
```

Routes with path parameters return a `starts_with` condition instead.

## Alert lifecycle

1. `SignalEvent` records event name, category, owner, tracked property, revenue, and allowlisted properties.
2. `SignalAlertRule` defines metric, operator, threshold, timeframe, cooldown, event filters, channels, and destination keys.
3. `SignalAlertEvaluator` evaluates the rule against matching events.
4. `SignalAlertDispatcher` writes `SignalAlertLog` records and dispatches configured channels.
5. `signals:process-alerts` runs scheduled evaluation.

## Generic filters

Alert rules can filter by:

- event names,
- event categories,
- tracked property,
- event property conditions.

This is intentionally package-agnostic: cart, checkout, orders, vouchers, affiliates, and future packages can all use the same rule engine.

## Channels

Supported dispatch channels:

- database,
- email,
- webhook,
- Slack-compatible webhook.

Named destinations from config are preferred. Inline destinations are ignored unless explicitly enabled.

## Evaluation strategy

- scheduled evaluation via `signals:process-alerts` is the baseline
- on-ingest evaluation can also be enabled through `signals.features.alerts.evaluate_on_ingest`
- queued evaluation is recommended when alerts or geocoding could slow down ingest requests

## Idempotency

`SignalEvent` supports an idempotency/source-event key unique per tracked property. Use it for listener retries and backfills.

## Owner scoping

Report, alert, and command paths are owner-scoped. Global rows require explicit global context for mutation.

## ReportRegistry

Signals uses a `ReportRegistry` to manage pluggable report types. Reports are registered in `SignalsServiceProvider` via anonymous classes implementing `ReportInterface`:

| Report Type | Backing Service |
|---|---|
| `page_view` | `PageViewReportService` |
| `conversion_funnel` | `ConversionFunnelReportService` |
| `acquisition` | `AcquisitionReportService` |
| `journey` | `JourneyReportService` |
| `retention` | `RetentionReportService` |
| `content_performance` | `ContentPerformanceReportService` |
| `devices` | `DevicesReportService` |
| `goals` | `GoalsReportService` |
| `live_activity` | `LiveActivityReportService` |

The registry is consumed by `SignalsDashboardService` and Filament admin surfaces. Each report implements `summary(?string $trackedPropertyId, ?string $from, ?string $until): array`, making the same data available outside the admin UI:

```php
use AIArmada\Signals\Reports\ReportRegistry;

$registry = app(ReportRegistry::class);

/** @var \AIArmada\Signals\Contracts\ReportInterface $report */
$report = $registry->get('page_view');
$summary = $report->summary(trackedPropertyId: $property->id);

foreach ($registry->all() as $report) {
    $data = $report->summary();
}
```

To add a custom report, implement `ReportInterface` and register it on the `ReportRegistry` singleton in your service provider.
