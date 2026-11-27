---
name: signals-development
description: Develop and integrate signals using owner-scoped Laravel Dune feature APIs.
---

# Signals development

- Use owner-scoped feature APIs for mutations: Ingestion owns properties and interaction rules, Reporting owns goals, segments and saved reports, and Alerts owns alert rules and read state.
- Resolve the owner context before reading or writing analytics. A report filter does not authorize access.
- Use `AIArmada\Signals\Features\Reporting\Api\DashboardRead` for dashboard summaries, trends and registered report summaries. Pass a tracked-property ID, not a model.
- Use `AIArmada\Signals\Features\Reporting\Api\SegmentOptions` for active segment choices.
- Use `AIArmada\Signals\Features\Ingestion\Api\SignalIngestion` with a tracked-property ID and explicit trust flag for application-owned event producers. The result is an immutable receipt.
- Browser ingestion is non-financial; trusted HTTP outcomes require a signed server request. Direct untrusted ingestion enforces the browser event policy and structural limits; application-owned endpoints must still enforce request byte limits, authentication, and rate limits.
- Use `AIArmada\Signals\Support\Http\SignalsBrowserContextManager` with the explicit request for request-local browser context integration, and the Ingestion `*ReportRead` interfaces for typed report summaries and rows. Filament database-backed tables use owner-scoped `SignalEvent` and `SignalSession` report query entry points; never import the internal report services from siblings.
- Browser page properties are composable: implement `AIArmada\Signals\Features\Browser\Api\BrowserPagePropertyContributor` and register it on `BrowserPagePropertyRegistry` instead of replacing the `BrowserPageProperties` binding.
- Keep external transports behind package contracts and drivers. Do not write another feature's tables directly.
