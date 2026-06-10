---
title: Overview
---

# Signals Package

## Purpose

The `aiarmada/signals` package is the analytics foundation for Commerce. It owns event ingestion, identity and session tracking, daily rollups, alerting, and reporting services.

## What this package owns

- Tracked events, identities, sessions, daily metrics, reports, goals, segments, and alert rules
- Event ingestion endpoints, browser context bootstrapping, and the tracker script endpoint
- Request metadata enrichment, session stitching, attribution dimensions, and device or bot analysis
- Reporting, alerting, route-aware filter helpers, and cross-package analytics listeners

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

- **Actions** — `IngestSignalEvent`, `ResolveSession`, `EvaluateAlertRules`, `IdentifySignalIdentity`, `CaptureSignalPageView`, `CaptureSignalGeolocation`, `ServeSignalsTracker`, and alert-read actions
- **Contracts** — `MapCommerceEventToSignalInterface`, `BrowserContextResolverInterface`, `ReportInterface`, `ReverseGeocoderContract`, `SignalLocationResolverContract`
- **Mappers** — `CartEventMapper`, `CheckoutEventMapper`, `OrderEventMapper`, `VoucherEventMapper`, `AffiliateEventMapper` (tagged `signals.event_mappers`)
- **Ingestion surface** — HTTP actions for identity, page-view, custom-event capture, geolocation capture, and tracker-script delivery
- **Browser surface** — browser-context cookies, optional automatic tracker injection, and Blade `@signalsTracker` rendering
- **Models and services** — signals domain models, report services (`PageViewReportService`, `SignalsDashboardService`, funnel/acquisition/journey/retention/content/goals/live/devices), alerting, ingestion helpers, route catalogs, and daily aggregation
- **ReportRegistry** — central registry for `ReportInterface` implementations, used by `SignalsDashboardService` and Filament admin surfaces
- **Integration surface** — `CommerceSignalsRecorder`, `RecordSignalFromEvent` listener, and mappers for cart, checkout, orders, vouchers, and affiliates

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
- Browser integration with `sig_vid` and `sig_sid` cookies, optional middleware auto-registration, and automatic HTML tracker injection
- Blade `@signalsTracker(...)` helper for explicit tracker placement and per-page overrides
- Daily metrics aggregation for dashboard and trend reporting
- Dedicated report services for page views, funnels, acquisition, journeys, retention, content performance, goals, live activity, and devices
- Saved reports, goals, segments, route-aware filter helpers, and alert rules
- Built-in tracker script endpoint for browser page-view and SPA navigation capture
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

When browser integration is enabled, Signals can also auto-create a browser tracked property per owner/global context and inject tracker markup into successful HTML responses.

## Package Structure

```text
src/
├── Actions/                 # Laravel Actions for ingest, session, alerts, identity, page views
├── Console/Commands/        # Daily aggregation + alert processing
├── Contracts/               # MapCommerceEventToSignalInterface, BrowserContextResolverInterface, ReportInterface
├── Data/                    # Spatie DTOs
├── Jobs/                    # Queued jobs (reverse geocode, alert evaluation)
├── Listeners/               # Commerce integration listeners
├── Mappers/                 # Commerce event mappers (cart, checkout, order, voucher, affiliate)
├── Models/                  # Signals domain models
├── Reports/                 # ReportRegistry for pluggable report types
├── Services/                # Reporting, dashboards, alerting, ingestion helpers, geocoding
├── Support/                 # Browser context, CrossTenantQuery, integration registrar, middleware
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
