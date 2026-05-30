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

## Campaign Attribution

Signals supports campaign-style attribution on page views, sessions, and events using common marketing fields:

- `utm_source`
- `utm_medium`
- `utm_campaign`
- `utm_content`
- `utm_term`
- `source`
- `medium`
- `campaign`
- `content`
- `term`
- `referrer`

The browser tracker automatically captures `utm_*` values from the landing page URL and sends the current page `referrer`. These values are persisted into Signals data so you can group traffic by channel, campaign, creative, or source.

### Recommended URL patterns

- **Google Ads**: `https://example.com/pricing?utm_source=google&utm_medium=cpc&utm_campaign=brand_search`
- **Social ads**: `https://example.com/offer?utm_source=facebook&utm_medium=paid_social&utm_campaign=ramadan_sale&utm_content=carousel_a`
- **Email**: `https://example.com/update?utm_source=newsletter&utm_medium=email&utm_campaign=may_launch`
- **WhatsApp share**: `https://example.com/deal?utm_source=whatsapp&utm_medium=share&utm_campaign=group_forward`

### Attribution guidance

- Prefer `utm_*` values for anything you control, because they are explicit and consistent.
- Treat `referrer` as a fallback signal only; it is often missing or stripped on messaging apps, short links, and some browser flows.
- Use a redirect or short-link service if you need to track WhatsApp or offline QR-code traffic reliably.
- Keep naming conventions stable so reporting can roll up traffic correctly over time.

### Manual event tracking

If you emit events from the server side, you can also include attribution fields in the payload you send to Signals so the event stays associated with the originating campaign.

## Browser Tracker

Signals serves the tracker script from:

`GET /api/signals/tracker.js`

The script sends automatic page-view payloads and tracks SPA navigation via `pushState`, `replaceState`, and `popstate`.

### Automatic browser integration

When `signals.integrations.browser.enabled` is `true`, Signals can bootstrap browser tracking for you:

- `signals.browser` middleware resolves or creates `sig_vid` and `sig_sid`
- the middleware queues those cookies onto the response
- successful `GET` HTML responses can receive an injected tracker tag automatically when `signals.integrations.browser.auto_inject` is `true`
- auto-injection skips binary/streamed responses and will not double-inject if you rendered `@signalsTracker(...)` manually

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

### Interaction rules

When `signals.integrations.browser.interaction_tracking.enabled` is `true`, the rendered tracker payload also includes active `SignalInteractionRule` definitions for the current tracked property.

- active rules are filtered to the current owner / explicit global context when owner mode is enabled
- rules scoped to a tracked property are included for that tracked property, alongside global rules with no tracked property set
- selector-less rules are omitted by default unless `include_rules_without_selector` is enabled
- `media` trigger rules are still included without a selector so the tracker can observe media events

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
