---
title: Usage
---

# Usage

## Ingestion Endpoints

Base path is `signals.http.prefix` (default `api/signals`).

### Identify

`POST /api/signals/collect/identify`

```json
{
  "write_key": "prop_write_key",
  "external_id": "user-123",
  "anonymous_id": "anon-abc",
  "email": "user@example.com",
  "traits": {
    "plan": "pro"
  }
}
```

When `auth_tracking` is enabled the currently authenticated Laravel user is automatically linked. You can also pass `auth_user_type` / `auth_user_id` explicitly:

### Page View

`POST /api/signals/collect/pageview`

```json
{
  "write_key": "prop_write_key",
  "session_identifier": "sig_session_1",
  "path": "/pricing",
  "url": "https://example.com/pricing",
  "title": "Pricing"
}
```

### Geolocation

`POST /api/signals/collect/geo`

```json
{
  "write_key": "prop_write_key",
  "session_identifier": "sig_session_1",
  "latitude": 3.139,
  "longitude": 101.6869,
  "accuracy": 25
}
```

This endpoint is intended for the browser tracker after the browser grants geolocation permission. When reverse geocoding is enabled, the session is enriched with resolved location fields and optional raw provider payload data.

Optional device fields can be passed explicitly. When `ua_parsing` is enabled these are populated automatically from the `User-Agent` header, but client-supplied values take precedence:

```json
{
  "device_type": "mobile",
  "device_brand": "Apple",
  "device_model": "iPhone 15",
  "browser": "Safari",
  "browser_version": "17.0",
  "os": "iOS",
  "os_version": "17.0",
  "is_bot": false
}
```

### Custom Event

`POST /api/signals/collect/event`

```json
{
  "write_key": "prop_write_key",
  "event_name": "checkout.completed",
  "event_category": "checkout",
  "session_identifier": "sig_session_1",
  "revenue_minor": 14900,
  "currency": "MYR",
  "properties": {
    "order_reference": "ORD-1001"
  }
}
```

## Browser Tracker

Signals serves the tracker script from:

`GET /api/signals/tracker.js`

The script sends automatic page-view payloads and tracks SPA navigation via `pushState`, `replaceState`, and `popstate`.

### Automatic browser integration

When `signals.integrations.browser.enabled` is `true`, Signals can bootstrap browser tracking for you:

- `signals.browser` middleware resolves or creates `sig_vid` and `sig_sid`
- the middleware queues those cookies onto the response
- successful `GET` HTML responses can receive an injected tracker tag automatically when `signals.integrations.browser.auto_inject` is `true`

If you keep `auto_register_middleware = true`, the middleware is appended to the configured group automatically.

### Explicit tracker rendering

If you want to place the tracker yourself, use the Blade directive:

```blade
@signalsTracker([
  'enableGeolocation' => true,
  'properties' => [
    'page_type' => 'pricing',
  ],
])
```

Supported overrides include:

- `enableGeolocation`
- `externalId`
- `email`
- `properties`

When Growth is installed and an experiment context is active, the renderer also includes the current experiment attribution in `data-page-properties` automatically.

### Manual script-tag requirements

If you bypass `@signalsTracker` and render the script tag manually, make sure it includes:

- `data-write-key`
- `data-anonymous-id`
- `data-session-id`

The built-in renderer and auto-injection flow populate those for you.

### What the browser tracker does

The tracker:

- sends an identify payload once per browser session when an external id is present
- records an initial page view immediately
- records additional page views on SPA navigation events
- optionally captures browser geolocation after a short delay when enabled
- posts to the configured page-view, identify, and geo endpoints

## Server-Side Event Recording

Use `CommerceSignalsRecorder` for direct recording from app/domain events.

```php
use AIArmada\Signals\Services\CommerceSignalsRecorder;

$recorder = app(CommerceSignalsRecorder::class);

$recorder->recordOrderPaid($order);
$recorder->recordCheckoutCompleted($checkout);
$recorder->recordAffiliateAttributed($attribution);
$recorder->recordAffiliateConversionRecorded($conversion);
```

The `signals.recording.events.*` toggles let you disable selected built-in recorder outputs without removing the surrounding integration.

## Aggregation and Alerting

### Aggregate Metrics

```bash
php artisan signals:aggregate-daily --days=7
php artisan signals:aggregate-daily --date=2026-03-10
php artisan signals:aggregate-daily --from=2026-03-01 --to=2026-03-10
```

### Process Alert Rules

```bash
php artisan signals:process-alerts
php artisan signals:process-alerts --rule=<alert-rule-id>
php artisan signals:process-alerts --dry-run
```
