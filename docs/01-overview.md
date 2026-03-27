---
title: Overview
---

# Signals Package

The `aiarmada/signals` package is the analytics foundation for commerce packages. It provides event ingestion, identity/session tracking, daily rollups, alerting, and report services with owner-aware scoping.

## Key Features

- Event ingestion endpoints for identity, page views, and custom events
- Session stitching and attribution dimensions (UTM/source/referrer/device)
- Automatic device, browser, OS, bot, and IP enrichment from request metadata
- Optional authenticated-user linkage during identity capture
- Optional browser geolocation capture with reverse-geocoded location enrichment
- Daily metrics aggregation for dashboard and trend reporting
- Saved reports, goals, segments, and alert rules
- Built-in tracker script endpoint for browser page-view capture
- Configurable monetary analytics so outcome-only installs can hide revenue-focused behavior
- Owner-aware multi-tenancy via `commerce-support`
- Automatic integration listeners for cart, checkout, orders, vouchers, and affiliates

Reverse geocoding uses a pipeline-based resolver. When enabled out of the box, the package registers the built-in Nominatim geocoder and will also honor any app-bound custom location resolver.

## Integrations

The package registers listeners only when related packages/events exist:

- Cart: item added, removed, cleared
- Checkout: started, completed
- Orders: paid
- Vouchers: applied, removed
- Affiliates: attributed, conversion recorded

## Package Structure

```text
src/
├── Actions/                 # HTTP actions for ingestion and tracker script
├── Console/Commands/        # Daily aggregation + alert processing
├── Listeners/               # Commerce integration listeners
├── Models/                  # Signals domain models
├── Services/                # Reporting, dashboards, alerting, ingestion helpers
├── Support/                 # Integration registrar and helpers
└── SignalsServiceProvider.php
```

## Requirements

- PHP 8.4+
- Laravel 11+
- `aiarmada/commerce-support`
