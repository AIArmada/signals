## Second pass — 2026-06-09

### Confirmed [done]

| Item | Status | Evidence |
|------|--------|----------|
| Phase 2 — Actions extracted | ✅ Done | 9 Actions exist: `IngestSignalEvent`, `EvaluateAlertRules`, `IdentifySignalIdentity`, `CaptureSignalPageView`, `CaptureSignalGeolocation`, `ServeSignalsTracker`, and 3 alert-marking Actions |
| Phase 3 — MapCommerceEventToSignalInterface | ✅ Done | `Contracts/MapCommerceEventToSignalInterface` exists; mappers (`OrderEventMapper`, `CartEventMapper`, `VoucherEventMapper`, `CheckoutEventMapper`, `AffiliateEventMapper`) registered via tagged service provider bindings |
| Phase 3 — Generic listener | ✅ Done | `Listeners/RecordSignalFromEvent` generic listener exists |
| Phase 4 — ReportRegistry | ✅ Done | `Reports/ReportRegistry` exists; all 8 reports registered via anonymous adapters in `SignalsServiceProvider` |
| Phase 5 — OwnerBatchRunner | ✅ Done | Both `AggregateDailyMetricsCommand` and `ProcessSignalAlertsCommand` migrated to use `OwnerBatchRunner` |

### Still open / issues

| Item | Status | Detail |
|------|--------|----------|
| Phase 1 — Services grouped [done] | ❌ **Not physically done** | All 25+ services are still in a flat `src/Services/` directory. No `Ingestion/`, `Reports/`, `Alerts/`, `Properties/`, `Geocoding/` subdirectories were created. The [done] status appears to mean "logically grouped" but files were never moved. |
| Phase 4 — Dashboard service uses registry | ✅ **Done** | `SignalsDashboardService` injects `ReportRegistry` and provides `availableReports()` / `report()` methods. Previous "not found" was stale. |
| Finding #4 — CommerceSignalsRecorder | ⚠️ Partially done | `IngestSignalEvent` Action now handles ingestion, but `CommerceSignalsRecorder` still exists as a service. Likely still a catch-all but reduced. |
| Developer experience | ⚠️ Concern | 26 flat service files + 9 Actions + 15 listeners + 2 jobs + browser context (5 files) = a lot of surface area. Grouping would improve navigability. |

### New findings

| Finding | Detail |
|---------|--------|
| `IngestSignalEvent` reduced from 491 to ~200 lines | Session resolution extracted into `Actions/ResolveSession`, cross-tenant queries extracted into `Support/CrossTenantQuery`. Action now delegates to sub-Actions. |
| No `BrowserContextResolver` contract created | Finding #6 from original review remains open — browser context is still 5 files with no contract-based resolver. |
| Event mappers registered via tag but not verified | `signals.event_mappers` tag mentioned in [done] — need to verify mappers are actually used by the generic `RecordSignalFromEvent` listener. |

### Updated recommendation

1. ~~Complete Phase 4~~ — Already done. ~~wire `SignalsDashboardService` to use `ReportRegistry`~~ — Already wired.
2. ~~Split `IngestSignalEvent`~~ — Done. Session resolution extracted to `ResolveSession`, cross-tenant queries to `CrossTenantQuery`.
3. **Physically group services** or remove the [done] claim from Phase 1.
4. **Add `BrowserContextResolver` contract** — reduce the 5-file browser context surface.
5. ~~Verify mapper wiring~~ — `RecordSignalFromEvent` correctly resolves mappers from `signals.event_mappers` tag.

---

# Signals friendliness review

This note reviews `packages/signals` against two repo-level expectations:

- when a capability may grow variants, prefer stable seams such as contracts, metadata, hooks, domain events, resolvers, and support classes
- when orchestration repeats, extract reusable Actions, Services, or Use Cases so the package stays friendly to multiple entrypoints

## What I reviewed

- `src/Services` (25+ classes)
- `src/Listeners` (15 classes)
- `src/Console/Commands` (2 classes)
- `src/Jobs` (2 classes)
- `src/Support`
- `src/Models` (10 classes)
- `src/Contracts` (2 classes)
- downstream consumers in `affiliates`, `cart`, `checkout`, `vouchers`, `orders`, `growth`

## What is already friendly

### Real geocoder contract

- `Contracts/ReverseGeocoderContract.php`
- `Services/Geocoders/NominatimGeocoder.php`

Geocoding is a real adapter seam. Adding a new provider (Google, Mapbox) is a new class.

### Real location resolver contract

- `Contracts/SignalLocationResolverContract.php`
- `Services/SignalLocationResolverPipeline.php`

Location resolution is a pipeline behind a contract. New resolver strategies can be added.

### Integration registrar isolates wiring

- `Support/CommerceSignalsIntegrationRegistrar.php`

The package plugs into the commerce ecosystem through a registrar, not by editing foundation.

### Listeners fan out from many commerce events

- 15 listeners reacting to cart, checkout, order, voucher, and affiliate events

This is the right shape for an analytics package — react to events, never reach into models.

### Background jobs handle async work

- `Jobs/EvaluateSignalAlertsForEvent.php`
- `Jobs/ReverseGeocodeSessionJob.php`

Long-running work is properly queued.

## Findings

### 1. Service count is very high (25+) with no Actions

**Files in `src/Services/`**

- `CommerceSignalsRecorder` (main ingest)
- `SignalsIngestionRequestValidator`
- `SignalsDashboardService`
- `TrackedPropertyResolver`
- `Signals/RouteCatalog`
- `SignalsEventConditionDefinition`
- `SignalsEventConditionMatcher`
- `SignalsEventConditionQueryService`
- `SignalsEventPropertyTypeInferrer`
- `SignalLocationResolverPipeline`
- `SignalMetricsAggregator`
- `SignalAlertEvaluator`
- `SignalAlertDispatcher`
- `SignalSegmentReportFilter`
- `SavedSignalReportDefinition`
- `SignalsIngestionRequestValidator`
- `SignalUserAgentParser`
- `Geocoders/NominatimGeocoder`
- `AcquisitionReportService`
- `ConversionFunnelReportService`
- `RetentionReportService`
- `JourneyReportService`
- `PageViewReportService`
- `ContentPerformanceReportService`
- `DevicesReportService`
- `GoalsReportService`
- `LiveActivityReportService`

**Why this hurts friendliness**

This is the largest service count in the monorepo. Many of these are read-side (reports) which is fine, but the ingestion and recording paths (recorder, validator, property type inferrer) are likely candidates for Actions.

**Recommendation**

Group services by domain:

- `Services/Ingestion/` (recorder, validator, property type inferrer)
- `Services/Reports/` (all the report services)
- `Services/Alerts/` (evaluator, dispatcher)
- `Services/Properties/` (definition, matcher, query, tracked property resolver)
- `Services/Geocoding/` (geocoder + pipeline)

Move mutations (record signal, evaluate alert, dispatch alert) to Actions:

- `Actions/RecordSignal`
- `Actions/EvaluateAlertRule`
- `Actions/DispatchAlert`

### 2. Listeners are large and likely do real work

**Files**

- 15 listeners under `src/Listeners/` including `RecordOrderPaidSignal`, `RecordCartAbandonedSignal`, `RecordCheckoutCompletedSignal`, etc.

**Why this hurts friendliness**

The listener count is high. Each listener likely maps a commerce event into a signal. If the mapping logic is inline, the package has 15 places to update when the signal model changes.

**Recommendation**

Extract a `MapCommerceEventToSignal` interface and one mapper per event family. The listeners become thin event adapters. This shrinks the listener surface to a small adapter per event.

### 3. Report services are 8 sibling classes

**Files**

- `AcquisitionReportService`
- `ConversionFunnelReportService`
- `RetentionReportService`
- `JourneyReportService`
- `PageViewReportService`
- `ContentPerformanceReportService`
- `DevicesReportService`
- `GoalsReportService`
- `LiveActivityReportService`

**Why this hurts friendliness**

This is the most report-heavy package in the monorepo. Each report is its own class. New report types will keep being added.

**Recommendation**

Add a `Reports/ReportInterface` and a registry. Each report registers itself. The dashboard service queries the registry for available reports.

### 4. `CommerceSignalsRecorder` is a likely catch-all orchestrator

**Files**

- `src/Services/CommerceSignalsRecorder.php`

**Why this hurts friendliness**

Like `AffiliateService` and `OrderService`, this is probably a single class that owns many operations. Every event listener and job calls it.

**Recommendation**

Audit for opportunity to delegate to Actions. Keep it as a compatibility facade but move mutations to the new Action tree.

### 5. Console commands duplicate owner-loop logic

**Files**

- `src/Console/Commands/AggregateDailyMetricsCommand.php`
- `src/Console/Commands/ProcessSignalAlertsCommand.php`

**Why this hurts friendliness**

The `ProcessSignalAlertsCommand` is the same pattern as the affiliates commands. Cross-package evidence is already strong (affiliates friendly.md cites this file).

**Recommendation**

Use `commerce-support`'s `OwnerBatchRunner` (when it lands) for both commands. Migrate the affiliates commands at the same time. Add characterization tests first.

### 6. Browser context is in `Support/` but not clearly a contract

**Files**

- `Support/Browser/SignalsBrowserContextManager.php`
- `Support/Browser/SignalsBrowserContext.php`
- `Support/Browser/InjectSignalsTrackerIntoHtmlResponse.php`
- `Support/Browser/SignalsTrackerRenderer.php`
- `Support/Http/Middleware/BootstrapSignalsBrowserContext.php`

**Why this hurts friendliness**

The browser context is split across 5 files. Adding a new context source (e.g., a different header scheme or signed payload) means editing all of them.

**Recommendation**

Extract a `BrowserContextResolver` contract. The manager and renderer become thin adapters. The resolver owns context source priority.

### 7. `Events/` is empty (the package consumes, not produces)

**Files**

- (none)

**Why this is worth noting**

This is the right shape for an analytics package. No outbound events, only inbound listeners. Keep this discipline.

### 8. Condition matching is a real engine

**Files**

- `Services/SignalsEventConditionDefinition.php`
- `Services/SignalsEventConditionMatcher.php`
- `Services/SignalsEventConditionQueryService.php`

This is data-driven and good. Consider promoting the definition/matcher/query to a `ConditionEngine` namespace for clarity.

## Concrete refactor plan

### Phase 1 — group services by domain

**Steps**

1. Create `Services/Ingestion/`, `Services/Reports/`, `Services/Alerts/`, `Services/Properties/`, `Services/Geocoding/` subfolders.
2. Move related services.
3. Update imports.

### Phase 2 — extract mutations to Actions

**Steps**

1. Move ingestion, alert evaluation, and alert dispatch to Actions.
2. Update listeners, jobs, and console commands.
3. Add tests for each Action.

### Phase 3 — extract event-to-signal mapping

**Steps**

1. Add `Events/MapCommerceEventToSignalInterface`.
2. Add one mapper per event family.
3. Listeners become thin adapters.

### Phase 4 — add report registry

**Steps**

1. Add `Reports/ReportInterface` and a registry.
2. Register all 8 built-in reports.
3. Update the dashboard service to use the registry.

### Phase 5 — adopt owner-batch helper

**Steps**

1. Wait for `commerce-support`'s `OwnerBatchRunner`.
2. Migrate `AggregateDailyMetricsCommand` and `ProcessSignalAlertsCommand`.





## Refactor tracking

This checklist tracks progress on the refactor plan above. Each item lists a concrete phase/step.
Agents: claim an item by updating its status. Use `@agent-name` to claim ownership.

Status legend:
- `[pending]` — not started
- `[in-progress]` — being worked on
- `[done]` — completed and verified
- `[blocked]` — blocked by another item

### Phase 1 — group services by domain

- [deferred] Create `Services/Ingestion/`, `Services/Reports/`, `Services/Alerts/`, `Services/Properties/`, `Services/Geocoding/` ... *(Reverted from [done] — all 25+ services are still in a flat `src/Services/` directory. Requires updating 108+ import references across signals, filament-signals, and other consumers. Large mechanical change deferred. — Deferred: large mechanical — 108+ import references need updating)*
- [deferred] Move related services. — Deferred: same as above
- [deferred] Update imports. — Deferred: same as above

### Phase 2 — extract mutations to Actions

- [done] Move ingestion, alert evaluation, and alert dispatch to Actions.
- [done] Update listeners, jobs, and console commands.
- [done] Add tests for each Action.

### Phase 3 — extract event-to-signal mapping

- [done] Add `Contracts/MapCommerceEventToSignalInterface`.
- [done] Add mappers: `OrderEventMapper`, `CartEventMapper`, `VoucherEventMapper`, `CheckoutEventMapper`, `AffiliateEventMapper`.
- [done] Add `Listeners/RecordSignalFromEvent` generic listener.
- [done] Register mappers via `signals.event_mappers` tag in service provider.

### Phase 4 — add report registry

- [done] Add `Contracts/ReportInterface` and `Reports/ReportRegistry`.
- [done] Register all 8 built-in reports via anonymous adapters in service provider.
- [done] Update the dashboard service to use the registry. — `SignalsDashboardService` now injects `ReportRegistry` and provides `availableReports()` and `report()` methods.

### Phase 5 — adopt owner-batch helper

- [done] `Commerce-support`'s `OwnerBatchRunner` is already available.
- [done] Migrate `AggregateDailyMetricsCommand` and `ProcessSignalAlertsCommand` to use `OwnerBatchRunner`.

### Phase 6 — split large Action and add BrowserContextResolver contract

- [done] Split `IngestSignalEvent` (491 lines) into sub-Actions: `ResolveIdentity` (already existed as `IdentifySignalIdentity`), `ResolveSession` (extracted to `Actions/ResolveSession`). Event persistence and alert evaluation remain in `IngestSignalEvent` but are now ~200 lines.
- [done] Extract repeated `withoutOwnerScope()` calls in `IngestSignalEvent` into `Support/CrossTenantQuery` helper. All cross-tenant event/session lookups now use the helper.
- [done] Add `Contracts/BrowserContextResolverInterface` contract — `SignalsBrowserContextResolver` implements it, bound in service provider.

### Phase 7 — verify mapper wiring and complete Phase 1 grouping

- [done] Verify `RecordSignalFromEvent` generic listener resolves mappers from `signals.event_mappers` container tag. — Confirmed: `RecordSignalFromEvent::resolveMapper()` iterates `$this->container->tagged('signals.event_mappers')` and calls `mapper->handles()` to match events. Wiring is correct.
- [deferred] Complete Phase 1 — physically move services into subdirectories (currently all flat). **108+ import references need updating. Deferred. — Deferred: same as above**



## Suggested verification scope

- per-Action tests for new mutation Actions
- listener tests
- report tests
- cross-package tests for affiliates/cart/checkout/vouchers/orders/growth after refactor

## Recommended first move

Phase 5 — adopt the owner-batch helper. This is the smallest, highest-leverage change because the duplication with affiliates is already proven and the helper lives in foundation. The other phases can follow.
