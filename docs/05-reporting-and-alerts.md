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

## Currency-explicit reporting

Revenue is always reported with its currency. Reports never convert or sum across currencies implicitly:

- A single-property scope returns `revenue_minor` plus the property's uppercase `currency` code (for example `['revenue_minor' => 129900, 'currency' => 'MYR']`).
- A multi-property scope returns a `revenue` map keyed by uppercase currency code, with totals sorted by code (for example `['revenue' => ['MYR' => 129900, 'USD' => 5000]]`).

The same shape applies to dashboard summaries and trends, report summaries and rows, funnel stages, and goal rows. Funnel revenue is attributed to the final stage only, using the matched event's own currency. Acquisition and content tables aggregate event revenue only when the event currency matches its property's reporting currency, so legacy foreign-currency rows contribute zero.

Custom reports can reuse the shared shaping helpers instead of implementing currency rules themselves:

```php
use AIArmada\Signals\Support\SignalReportRevenue;

$summary = [
    'events' => 42,
    ...SignalReportRevenue::shape($trackedPropertyId, $revenueByCurrency),
];

// Group a filtered event query by uppercase currency.
$revenueByCurrency = SignalReportRevenue::byCurrency($query);
```

## Saved reports and segments

- `SavedSignalReport` stores reusable report state for supported report types
- `SignalSegment` stores reusable filter conditions that can be applied across reports and alerts
- both remain owner-scoped and should be resolved inside the current owner context

## Route-aware report filters

`SignalRouteCatalog` can convert named routes into path conditions that are safe to reuse in reports or segments:

```php
use AIArmada\Signals\Support\SignalRouteCatalog;

// Returns null for unknown or ineligible routes.
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
6. `DispatchSignalAlertDelivery` claims each delivery, sends outside any database transaction, then finalizes under a fresh row lock: a terminal `sent`/`dead` row always wins, a stale completion never regresses a newer claim, and `sent_at`/`dead_at` are preserved once set.

## Generic filters

Alert rules can filter by:

- event names,
- event categories,
- tracked property,
- event property conditions.

This is intentionally package-agnostic: cart, checkout, orders, vouchers, affiliates, and future packages can all use the same rule engine.

Revenue alert rules are currency-explicit. A rule scoped to a tracked property evaluates in that property's reporting currency. A global revenue rule must set `event_filters.currency`; the model rejects a new revenue rule without one. Events whose currency is missing or different never match a revenue rule, and a legacy revenue rule with no resolvable currency evaluates to `0.0` instead of guessing.

## Channels

Supported dispatch channels:

- database,
- email,
- webhook,
- Slack-compatible webhook.

Named destinations from config are preferred. Inline destinations are ignored unless explicitly enabled. With owner scoping enabled, global rules can use named destinations and tenant rules can use inline destinations. Inline rule destinations and queued delivery destinations are encrypted at rest. Inline rule destinations are excluded from audits, activity logs, and model serialization. Each inline entry declares its channel and destination fields.

```php
'inline_destinations' => [
    ['channel' => 'email', 'destination' => ['to' => 'ops@example.com']],
    ['channel' => 'slack', 'destination' => ['webhook_url' => 'https://hooks.slack.com/services/...']],
],
```

Delivery retries retain the specific sanitized failure code (`http_5xx`, `timeout`, `invalid_destination`, `unsafe_destination`, `unsupported_channel`). A worker that finds an active lease releases the queued job until the lease expires instead of acknowledging it, and a delivery whose alert log was already deleted is removed rather than retried.

## Evaluation strategy

- scheduled evaluation via `signals:process-alerts` is the baseline
- on-ingest evaluation can also be enabled through `signals.features.alerts.evaluate_on_ingest.enabled` (with `signals.features.alerts.evaluate_on_ingest.queue` controlling whether evaluation is queued)
- queued evaluation is recommended when alerts or geocoding could slow down ingest requests

## Idempotency

`SignalEvent` deduplicates on `(tracked_property_id, ingestion_source, idempotency_key)`: the same raw key submitted on browser and trusted boundaries creates two distinct events, while retries within one boundary return the original event. Trusted paths fall back to `source_event_id` when no explicit key is given. Use keys for listener retries within one boundary.

## Owner scoping

Report, alert, and command paths are owner-scoped. Global rows require explicit global context for mutation.

## ReportRegistry

Signals uses a `ReportRegistry` to manage pluggable report types. Telemetry-backed reports are declared in the Ingestion feature manifest and registered by the Reporting feature module via anonymous classes implementing `ReportInterface`:

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

The registry is consumed by the Reporting feature's `DashboardRead` implementation. Each report implements `summary(?string $trackedPropertyId, ?string $from, ?string $until): array`, making the same data available outside the admin UI:

```php
use AIArmada\Signals\Features\Reporting\Api\DashboardRead;

$dashboard = app(DashboardRead::class);
$summary = $dashboard->report('page_view', trackedPropertyId: $property->id);

foreach ($dashboard->availableReports() as $type => $name) {
    $data = $dashboard->report($type);
}
```

To add a custom report, implement `ReportInterface` and register it on the Reporting feature's `ReportRegistry` singleton in your service provider. Built-in telemetry readers implement the interface directly and are discovered through the `signals.reports` extension slot.
