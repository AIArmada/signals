---
title: Configuration
---

# Configuration

Signals configuration lives in `config/signals.php`.

## Database

```php
'database' => [
    'table_prefix' => 'signal_',
    'json_column_type' => env('SIGNALS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
    'tables' => [
        'tracked_properties' => 'signal_tracked_properties',
        'identities'         => 'signal_identities',
        'sessions'           => 'signal_sessions',
        'events'             => 'signal_events',
        'interaction_rules'  => 'signal_interaction_rules',
        'daily_metrics'      => 'signal_daily_metrics',
        'goals'              => 'signal_goals',
        'segments'           => 'signal_segments',
        'saved_reports'      => 'signal_saved_reports',
        'alert_rules'        => 'signal_alert_rules',
        'alert_logs'         => 'signal_alert_logs',
    ],
],
```

Table names can be overridden individually in `database.tables`. All tables default to the `signal_` prefix.

## Defaults

```php
'defaults' => [
    'currency'                     => 'MYR',
    'timezone'                     => 'UTC',
    'property_type'                => 'website',
    'page_view_event_name'         => 'page_view',
    'primary_outcome_event_name'   => env('SIGNALS_PRIMARY_OUTCOME_EVENT_NAME', 'conversion.completed'),
    'starter_funnel' => [
        ['label' => 'Visited',           'event_name' => 'page_view', 'event_category' => 'page_view'],
        ['label' => 'Explored Further',  'event_name' => 'page_view', 'event_category' => 'page_view'],
        ['label' => 'Completed Outcome', 'event_name' => null,        'event_category' => null],
    ],
    'session_duration_seconds' => 1800,
],
```

`primary_outcome_event_name` controls which event is used for primary-outcome goal calculations. `starter_funnel` is the default funnel definition shown before the user configures a custom one.

## Recording

```php
'recording' => [
    'events' => [
        'checkout.started' => true,
        'checkout.completed' => true,
        'order.paid' => true,
        'order.refunded' => true,
    ],
],
```

These toggles let you suppress specific built-in commerce recordings without disabling the entire integration surface.

## Owner

```php
'owner' => [
    'enabled'              => false,
    'include_global'       => false,
    'auto_assign_on_create' => true,
],
```

Owner mode is opt-in for Signals. With the default config, browser/global analytics can run without a resolved owner. Once you enable owner mode, tracked-property resolution, writes, and admin queries follow the configured owner boundary and require either a resolved owner or explicit global context.

## Features

### User-Agent Parsing

```php
'features' => [
    'ua_parsing' => [
        'enabled'   => true,
        'store_raw' => true, // store raw User-Agent string on signal_sessions
    ],
],
```

### IP Tracking

```php
'features' => [
    'ip_tracking' => [
        'enabled'   => true,
        'anonymize' => false, // true = zero-out last octet (IPv4) / last 80 bits (IPv6)
    ],
],
```

### Auth Tracking

```php
'features' => [
    'auth_tracking' => [
        'enabled' => false, // opt-in: links auth()->user() to SignalIdentity on identify
    ],
],
```

When enabled, the currently authenticated Laravel user is automatically linked during identity capture. You can also pass `auth_user_type` / `auth_user_id` explicitly in the identify payload.

### Geolocation

```php
'features' => [
    'geolocation' => [
        'enabled' => true, // allow browser coordinate capture via /collect/geo
        'reverse_geocode' => [
            'enabled'           => false, // opt-in: resolve coordinates to address fields
            'async'             => true,  // dispatch ReverseGeocodeSessionJob instead of inline
            'store_raw_payload' => false, // persist raw provider response on the session
        ],
    ],
],
```

Geolocation capture is available but reverse geocoding is opt-in. When `async` is true, a queued job (`ReverseGeocodeSessionJob`) handles the resolution so the ingest request is not blocked.

### Monetary

```php
'features' => [
    'monetary' => [
        'enabled' => true, // false = hide all revenue UI: stat cards, columns, goal types, alert metrics
    ],
],
```

### Privacy — Property Allowlist

```php
'features' => [
    'privacy' => [
        'property_allowlist' => [
            'affiliate_code', 'affiliate_id', 'attribution_id',
            'assignment_id',
            'cart_id', 'cart_identifier', 'cart_instance', 'cart_total_minor',
            'channel', 'checkout', 'checkout_session_id',
            'commission_minor', 'conversion_id', 'conversion_type',
            'cookie_value', 'currency', 'external_reference',
            'experiment_contexts', 'experiment_id', 'experiment_slug',
            'first_order', 'gateway', 'item_count', 'item_id', 'item_name',
            'items_count', 'landing_url', 'line_total_minor', 'medium',
            'module_type',
            'order_id', 'order_number', 'order_reference',
            'payment_gateway', 'quantity', 'referrer_url',
            'refund_reason',
            'shipping_method', 'source_event_id', 'status',
            'subtotal_minor', 'subject_identifier', 'subject_instance',
            'title', 'total_minor', 'total_quantity', 'transaction_id',
            'unique_item_count', 'unit_price_minor', 'value_minor',
            'variant_code', 'variant_id',
            'voucher_code', 'voucher_id', 'voucher_name', 'voucher_type', 'voucher_value',
        ],
    ],
],
```

Raw PII such as email, phone, names, and full metadata is excluded by default. Only the keys listed here are stored on `SignalEvent.properties`. Add operational fields that are safe to store; remove any that are sensitive for your use case.

The default allowlist now includes Growth attribution keys such as `experiment_id`, `variant_id`, `assignment_id`, and `experiment_contexts`.

### Alerts

```php
'features' => [
    'alerts' => [
        'evaluate_on_ingest' => [
            'enabled' => false,
            'queue'   => true,
        ],
        'allow_inline_destinations' => false,
        'default_channels'          => ['database'],
        'destinations' => [
            'email'   => [],
            'webhook' => [],
            'slack'   => [],
        ],
    ],
],
```

Scheduled alert evaluation (`signals:process-alerts`) is the baseline. On-ingest evaluation is optional and queued by default. Named destinations in `destinations.*` are referenced by key in alert rules; inline destinations are ignored unless `allow_inline_destinations` is true.

## Integrations

Each integration is independently toggled. Browser and cart integrations default to `enabled: false`; checkout, orders, vouchers, and affiliates default to `enabled: true`.

### Browser

```php
'integrations' => [
    'browser' => [
        'enabled' => false,
        'auto_register_middleware' => true,
        'middleware_group' => 'web',
        'auto_inject' => true,
        'interaction_tracking' => [
            'enabled' => true,
            'include_rules_without_selector' => false,
        ],
        'identifiers' => [
            'visitor_cookie_name' => 'sig_vid',
            'session_cookie_name' => 'sig_sid',
            'visitor_cookie_ttl_seconds' => 31_536_000,
            'session_cookie_ttl_seconds' => 1_800,
            'path' => '/',
            'domain' => null,
            'secure' => null,
            'http_only' => true,
            'same_site' => 'lax',
        ],
        'tracked_property' => [
            'auto_create' => true,
            'slug' => 'commerce-browser',
            'name' => 'Commerce Browser',
        ],
        'identify' => [
            'enabled' => true,
        ],
        'geolocation' => [
            'enabled' => true,
        ],
    ],
],
```

Browser integration controls:

- middleware bootstrapping for browser cookies and session state
- optional automatic tracker injection into successful `GET` HTML responses
- serialization of active `SignalInteractionRule` definitions into the browser tracker payload
- default cookie names and lifetimes
- automatic tracked-property creation for browser analytics
- whether browser identify / geolocation payloads should be emitted

### Cart

```php
'integrations' => [
    'cart' => [
        'enabled'                  => false,
        'listen_for_item_added'    => true,
        'listen_for_item_removed'  => true,
        'listen_for_cleared'       => true,
        'item_added_event_name'    => 'cart.item.added',
        'item_removed_event_name'  => 'cart.item.removed',
        'cleared_event_name'       => 'cart.cleared',
        'event_category'           => 'cart',
        'tracked_property' => [
            'auto_create' => true,
            'slug'        => 'commerce-cart',
            'name'        => 'Commerce Cart',
        ],
    ],
],
```

### Filament Cart

```php
'integrations' => [
    'filament_cart' => [
        'enabled'                          => false,
        'listen_for_snapshot_synced'       => true,
        'listen_for_checkout_started'      => true,
        'listen_for_abandoned'             => true,
        'listen_for_high_value_detected'   => true,
        'snapshot_synced_event_name'       => 'cart.snapshot.synced',
        'checkout_started_event_name'      => 'cart.checkout.started',
        'abandoned_event_name'             => 'cart.abandoned',
        'high_value_detected_event_name'   => 'cart.high_value.detected',
        'event_category'                   => 'cart',
        'tracked_property' => [
            'auto_create' => true,
            'slug'        => 'commerce-cart',
            'name'        => 'Commerce Cart',
        ],
    ],
],
```

### Checkout

```php
'integrations' => [
    'checkout' => [
        'enabled'               => true,
        'listen_for_started'    => true,
        'listen_for_completed'  => true,
        'started_event_name'    => 'checkout.started',
        'event_name'            => 'checkout.completed',
        'event_category'        => 'checkout',
    ],
],
```

### Orders

```php
'integrations' => [
    'orders' => [
        'enabled'          => true,
        'listen_for_paid'  => true,
        'listen_for_refunded' => true,
        'event_name'       => 'order.paid',
        'event_category'   => 'conversion',
        'refund_event_name' => 'order.refunded',
        'refund_event_category' => 'conversion',
    ],
],
```

### Vouchers

```php
'integrations' => [
    'vouchers' => [
        'enabled'               => true,
        'listen_for_applied'    => true,
        'listen_for_removed'    => true,
        'applied_event_name'    => 'voucher.applied',
        'removed_event_name'    => 'voucher.removed',
        'event_category'        => 'promotion',
    ],
],
```

### Affiliates

```php
'integrations' => [
    'affiliates' => [
        'enabled'                        => true,
        'listen_for_attributed'          => true,
        'listen_for_conversion_recorded' => true,
        'attributed_event_name'          => 'affiliate.attributed',
        'attributed_event_category'      => 'acquisition',
        'conversion_event_name'          => 'affiliate.conversion.recorded',
        'conversion_event_category'      => 'conversion',
    ],
],
```

Browser, cart, and Filament cart integrations can auto-create deterministic tracked properties per owner / global context when no active property exists.

## HTTP

```php
'http' => [
    'prefix'         => 'api/signals',
    'middleware'     => ['api'],
    'tracker_script' => 'tracker.js',
],
```

`prefix` is the base path for all ingestion and tracker endpoints. `tracker_script` is the filename served at `{prefix}/{tracker_script}`. Override `middleware` to add rate limiting or custom guards.

## Mappers

Commerce event mappers translate domain events into signal event payloads. They implement `MapCommerceEventToSignalInterface` and are registered via the `signals.event_mappers` tag in the service provider:

```php
// SignalsServiceProvider
$this->app->tag([
    OrderEventMapper::class,
    CartEventMapper::class,
    VoucherEventMapper::class,
    CheckoutEventMapper::class,
    AffiliateEventMapper::class,
], 'signals.event_mappers');
```

The `RecordSignalFromEvent` listener resolves the correct mapper for each dispatched event by iterating tagged services. Mappers are cached by event class after first resolution.

To register a custom mapper, implement `MapCommerceEventToSignalInterface` and tag your service with `signals.event_mappers`:

```php
$this->app->tag(MyCustomMapper::class, 'signals.event_mappers');
```

When `handledEvents()` is defined on the mapper, the resolver checks all returned class names; otherwise it falls back to `handles()`. This lets a single mapper handle multiple event types, as `CartEventMapper` does for `ItemAdded`, `ItemRemoved`, and `CartCleared`.
