---
title: Configuration
---

# Configuration

Configuration is defined in `config/signals.php`.

## Database

```php
'database' => [
    'table_prefix' => 'signal_',
    'json_column_type' => env('SIGNALS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'json')),
    'tables' => [
        'tracked_properties' => 'signal_tracked_properties',
        'identities' => 'signal_identities',
        'sessions' => 'signal_sessions',
        'events' => 'signal_events',
        'daily_metrics' => 'signal_daily_metrics',
        'goals' => 'signal_goals',
        'segments' => 'signal_segments',
        'saved_reports' => 'signal_saved_reports',
        'alert_rules' => 'signal_alert_rules',
        'alert_logs' => 'signal_alert_logs',
    ],
],
```

`json_column_type` is also used by the geolocation enrichment migration when storing `raw_reverse_geocode_payload`, so PostgreSQL installs can switch that column to `jsonb` without editing migrations.

## Defaults

```php
'defaults' => [
    'currency' => 'MYR',
    'timezone' => 'UTC',
    'property_type' => 'website',
    'page_view_event_name' => 'page_view',
    'primary_outcome_event_name' => env('SIGNALS_PRIMARY_OUTCOME_EVENT_NAME', 'conversion.completed'),
    'starter_funnel' => [/* ... */],
    'session_duration_seconds' => 1800,
],
```

## Features / Behavior

```php
'features' => [
    'owner' => [
        'enabled' => true,
        'include_global' => false,
        'auto_assign_on_create' => true,
    ],
    'ua_parsing' => [
        'enabled' => true,       // auto-parse User-Agent on every ingestion request
        'store_raw' => true,     // persist the raw User-Agent string on signal_sessions
    ],
    'ip_tracking' => [
        'enabled' => true,       // capture client IP on session creation
        'anonymize' => false,    // true = zero last octet (IPv4) / last 80 bits (IPv6)
    ],
    'auth_tracking' => [
        'enabled' => false,      // opt-in: link auth()->user() to SignalIdentity on identify calls
    ],
    'geolocation' => [
        'enabled' => true,       // allow browser coordinate capture via /collect/geo
        'reverse_geocode' => [
            'enabled' => false,  // enrich sessions with address/location fields
            'async' => true,     // queue ReverseGeocodeSessionJob instead of resolving inline
            'store_raw_payload' => false,
        ],
    ],
    'monetary' => [
        'enabled' => true,       // false = hide revenue-oriented analytics behavior in dependent UIs
    ],
],
```

### `ua_parsing`

When `enabled`, every ingestion request automatically parses the `User-Agent` header using `matomo/device-detector` and populates `device_type`, `device_brand`, `device_model`, `browser`, `browser_version`, `os`, `os_version`, and `is_bot` on the session. Client-supplied values take precedence, but `is_bot` is always server-authoritative.

Set `store_raw => false` to skip persisting the raw User-Agent string.

### `ip_tracking`

When `enabled`, the client IP is captured on session creation. Set `anonymize => true` to truncate the IP before storage (last octet for IPv4, last 80 bits for IPv6).

### `auth_tracking`

Opt-in. When `enabled`, `IdentifySignalIdentity` automatically links the currently authenticated Laravel user (`auth()->user()`) to the identity record via `auth_user_type` / `auth_user_id`. Requires a session authenticated via a standard Laravel guard.

### `geolocation`

When `enabled`, the browser tracker can post coordinates to `POST /collect/geo` for the active session. If `reverse_geocode.enabled` is also true, the package resolves country, region, locality, postal code, and formatted address fields onto the session.

Set `async => false` to resolve reverse geocoding inline. Leave it `true` when you want enrichment handled by the queue via `ReverseGeocodeSessionJob`.

Set `store_raw_payload => true` only when you explicitly need provider-specific debug payloads in `raw_reverse_geocode_payload`.

### `monetary`

When `enabled => false`, dependent packages such as `aiarmada/filament-signals` hide monetary stat cards, columns, goal options, and alert metrics while still keeping outcome/event analytics active.

## Integrations

Each integration can be toggled and event names/categories are configurable:

- `integrations.cart.*`
- `integrations.checkout.*`
- `integrations.orders.*`
- `integrations.vouchers.*`
- `integrations.affiliates.*`

Affiliate events use:

- `affiliate.attributed`
- `affiliate.conversion.recorded`

by default.

## HTTP

```php
'http' => [
    'prefix' => 'api/signals',
    'middleware' => ['api'],
    'tracker_script' => 'tracker.js',
],
```

This controls ingestion and tracker script routes.
