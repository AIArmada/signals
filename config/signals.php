<?php

declare(strict_types=1);

$tablePrefix = 'signal_';

return [
    /* Database */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('SIGNALS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
        'tables' => [
            'tracked_properties' => $tablePrefix . 'tracked_properties',
            'identities' => $tablePrefix . 'identities',
            'sessions' => $tablePrefix . 'sessions',
            'events' => $tablePrefix . 'events',
            'interaction_rules' => $tablePrefix . 'interaction_rules',
            'daily_metrics' => $tablePrefix . 'daily_metrics',
            'goals' => $tablePrefix . 'goals',
            'segments' => $tablePrefix . 'segments',
            'saved_reports' => $tablePrefix . 'saved_reports',
            'alert_rules' => $tablePrefix . 'alert_rules',
            'alert_logs' => $tablePrefix . 'alert_logs',
        ],
    ],

    /* Defaults */
    'defaults' => [
        'currency' => 'MYR',
        'timezone' => 'UTC',
        'property_type' => 'website',
        'page_view_event_name' => 'page_view',
        'primary_outcome_event_name' => env('SIGNALS_PRIMARY_OUTCOME_EVENT_NAME', 'conversion.completed'),
        'starter_funnel' => [
            [
                'label' => 'Visited',
                'event_name' => 'page_view',
                'event_category' => 'page_view',
            ],
            [
                'label' => 'Explored Further',
                'event_name' => 'page_view',
                'event_category' => 'page_view',
            ],
            [
                'label' => 'Completed Outcome',
                'event_name' => null,
                'event_category' => null,
            ],
        ],
        'session_duration_seconds' => 1800,
    ],

    /* Recording toggles */
    'recording' => [
        'events' => [
            'checkout.started' => env('SIGNALS_RECORDING_EVENT_CHECKOUT_STARTED', true),
            'checkout.completed' => env('SIGNALS_RECORDING_EVENT_CHECKOUT_COMPLETED', true),
            'order.paid' => env('SIGNALS_RECORDING_EVENT_ORDER_PAID', true),
            'order.refunded' => env('SIGNALS_RECORDING_EVENT_ORDER_REFUNDED', true),
        ],
    ],

    /* Owner */
    'owner' => [
        'enabled' => false,
        'include_global' => false,
        'auto_assign_on_create' => true,
    ],

    /* Features / Behavior */
    'features' => [
        'ua_parsing' => [
            'enabled' => true,
            'store_raw' => true, // store the raw User-Agent string on signal_sessions
        ],
        'ip_tracking' => [
            'enabled' => true,
            'anonymize' => false, // true = zero-out last octet (IPv4) / last 80 bits (IPv6)
        ],
        'auth_tracking' => [
            'enabled' => false, // opt-in: when true, links auth()->user() to SignalIdentity
        ],
        'geolocation' => [
            'enabled' => true,  // allow browser geolocation coordinate capture via /collect/geo
            'reverse_geocode' => [
                'enabled' => false,  // opt-in: reverse-geocode coordinates to address fields
                'async' => true,     // dispatch ReverseGeocodeSessionJob instead of inline
                'store_raw_payload' => false, // persist raw provider response in raw_reverse_geocode_payload
            ],
        ],
        'monetary' => [
            'enabled' => true,  // false = hide all monetary/revenue UI (stat cards, columns, goal types, alert metrics, condition fields)
        ],
        'privacy' => [
            'property_allowlist' => [
                'assignment_id',
                'affiliate_code',
                'affiliate_id',
                'attribution_id',
                'cart_id',
                'cart_identifier',
                'cart_instance',
                'cart_total_minor',
                'channel',
                'checkout',
                'checkout_session_id',
                'commission_minor',
                'conversion_id',
                'conversion_type',
                'cookie_value',
                'currency',
                'external_reference',
                'experiment_contexts',
                'experiment_id',
                'experiment_slug',
                'first_order',
                'gateway',
                'item_count',
                'item_id',
                'item_name',
                'items_count',
                'landing_url',
                'line_total_minor',
                'medium',
                'module_type',
                'order_id',
                'order_number',
                'order_reference',
                'payment_gateway',
                'quantity',
                'referrer_url',
                'refund_reason',
                'shipping_method',
                'source_event_id',
                'status',
                'subtotal_minor',
                'subject_identifier',
                'subject_instance',
                'title',
                'total_minor',
                'total_quantity',
                'transaction_id',
                'unique_item_count',
                'unit_price_minor',
                'value_minor',
                'voucher_code',
                'voucher_id',
                'voucher_name',
                'voucher_type',
                'voucher_value',
                'variant_code',
                'variant_id',
            ],
        ],
        'alerts' => [
            'evaluate_on_ingest' => [
                'enabled' => false,
                'queue' => true,
            ],
            'allow_inline_destinations' => false,
            'default_channels' => ['database'],
            'destinations' => [
                'email' => [],
                'webhook' => [],
                'slack' => [],
            ],
        ],
    ],

    /* Integrations */
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
        'cart' => [
            'enabled' => false,
            'listen_for_item_added' => true,
            'listen_for_item_removed' => true,
            'listen_for_cleared' => true,
            'item_added_event_name' => 'cart.item.added',
            'item_removed_event_name' => 'cart.item.removed',
            'cleared_event_name' => 'cart.cleared',
            'event_category' => 'cart',
            'tracked_property' => [
                'auto_create' => true,
                'slug' => 'commerce-cart',
                'name' => 'Commerce Cart',
            ],
        ],
        'filament_cart' => [
            'enabled' => false,
            'listen_for_snapshot_synced' => true,
            'listen_for_checkout_started' => true,
            'listen_for_abandoned' => true,
            'listen_for_high_value_detected' => true,
            'snapshot_synced_event_name' => 'cart.snapshot.synced',
            'checkout_started_event_name' => 'cart.checkout.started',
            'abandoned_event_name' => 'cart.abandoned',
            'high_value_detected_event_name' => 'cart.high_value.detected',
            'event_category' => 'cart',
            'tracked_property' => [
                'auto_create' => true,
                'slug' => 'commerce-cart',
                'name' => 'Commerce Cart',
            ],
        ],
        'checkout' => [
            'enabled' => true,
            'listen_for_started' => true,
            'listen_for_completed' => true,
            'started_event_name' => 'checkout.started',
            'event_name' => 'checkout.completed',
            'event_category' => 'checkout',
        ],
        'orders' => [
            'enabled' => true,
            'listen_for_paid' => true,
            'listen_for_refunded' => true,
            'event_name' => 'order.paid',
            'event_category' => 'conversion',
            'refund_event_name' => 'order.refunded',
            'refund_event_category' => 'conversion',
        ],
        'vouchers' => [
            'enabled' => true,
            'listen_for_applied' => true,
            'listen_for_removed' => true,
            'applied_event_name' => 'voucher.applied',
            'removed_event_name' => 'voucher.removed',
            'event_category' => 'promotion',
        ],
        'affiliates' => [
            'enabled' => true,
            'listen_for_attributed' => true,
            'listen_for_conversion_recorded' => true,
            'attributed_event_name' => 'affiliate.attributed',
            'attributed_event_category' => 'acquisition',
            'conversion_event_name' => 'affiliate.conversion.recorded',
            'conversion_event_category' => 'conversion',
        ],
        'affiliate_network' => [
            'enabled' => true,
            'listen_for_offer_created' => true,
            'listen_for_offer_updated' => true,
            'listen_for_application_submitted' => true,
            'listen_for_application_approved' => true,
            'listen_for_network_conversion_recorded' => true,
        ],
    ],

    /* HTTP */
    'http' => [
        'prefix' => 'api/signals',
        'middleware' => ['api'],
        'tracker_script' => 'tracker.js',
    ],
];
