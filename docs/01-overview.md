---
title: Overview
---

# Signals Package

## Purpose

The `aiarmada/signals` package is the analytics foundation for Commerce. It owns event ingestion, identity and session tracking, daily rollups, alerting, and reporting services.

## What this package owns

- Tracked events, identities, sessions, daily metrics, reports, goals, segments, and alert rules
- Event ingestion endpoints and the browser tracker script endpoint
- Request metadata enrichment, session stitching, attribution dimensions, and device or bot analysis
- Reporting, alerting, and cross-package analytics listeners

## What this package does not own

- Domain-specific business state from cart, checkout, orders, vouchers, or affiliates; it listens to those systems rather than owning them
- Filament admin surfaces; those belong to `aiarmada/filament-signals`
- Revenue or commerce outcomes outside the event data those packages emit into Signals

## Related packages

- [`aiarmada/filament-signals`](../../filament-signals/docs/01-overview.md) — Filament analytics UI and management resources
- [`aiarmada/cart`](../../cart/docs/01-overview.md), [`aiarmada/checkout`](../../checkout/docs/01-overview.md), [`aiarmada/orders`](../../orders/docs/01-overview.md), [`aiarmada/vouchers`](../../vouchers/docs/01-overview.md), and [`aiarmada/affiliates`](../../affiliates/docs/01-overview.md) — common event sources
- [`aiarmada/growth`](../../growth/docs/01-overview.md) — experimentation context enrichment built on top of Signals data
- [`aiarmada/commerce-support`](../../commerce-support/docs/01-overview.md) — owner scoping and shared utilities

## Main models services or surfaces

- **Ingestion surface** — HTTP actions for identity, page-view, and custom-event capture plus tracker-script delivery
- **Models and services** — signals domain models, reporting, dashboards, alerting, ingestion helpers, and daily aggregation
- **Integration surface** — package listeners and registrar hooks for cart, checkout, orders, vouchers, and affiliates

## Owner scoping and security notes

- Signals data is owner-aware and should follow the `commerce-support` owner-boundary rules
- Analytics filters are not authorization; reporting endpoints and alert actions should still resolve records inside the current owner scope
- Reverse geocoding and enrichment should be treated as analytics context, not identity proof or security validation

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

## Read next

- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Usage](04-usage.md)
- [Reporting and alerts](05-reporting-and-alerts.md)
- [Troubleshooting](99-troubleshooting.md)
- [Filament Signals overview](../../filament-signals/docs/01-overview.md)
