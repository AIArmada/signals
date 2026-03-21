---
title: Overview
---

# Signals Package

The `aiarmada/signals` package is the analytics foundation for commerce packages. It provides event ingestion, identity/session tracking, daily rollups, alerting, and report services with owner-aware scoping.

## Key Features

- Event ingestion endpoints for identity, page views, and custom events
- Session stitching and attribution dimensions (UTM/source/referrer/device)
- Daily metrics aggregation for dashboard and trend reporting
- Saved reports, goals, segments, and alert rules
- Built-in tracker script endpoint for browser page-view capture
- Owner-aware multi-tenancy via `commerce-support`
- Automatic integration listeners for cart, checkout, orders, vouchers, and affiliates

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
