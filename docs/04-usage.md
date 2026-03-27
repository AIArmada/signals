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
  "accuracy_meters": 25
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

Serve tracker script from:

`GET /api/signals/tracker.js`

The script sends automatic page-view payloads and tracks SPA navigation via `pushState`, `replaceState`, and `popstate`.

To enable browser-driven coordinate capture, render the script tag with `data-enable-geolocation="true"`. When disabled, the tracker skips `navigator.geolocation` entirely.

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
