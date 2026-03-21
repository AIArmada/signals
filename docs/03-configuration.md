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
],
```

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
