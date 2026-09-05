---
title: Signals Context
package: signals
status: current
surface: analytics
family: analytics-and-events
keywords:
  - analytics
  - event-tracking
  - funnel
  - session
  - alert
---

# Signals Context

## Snapshot
- Composer: `aiarmada/signals`
- Role: Privacy-first behavioural analytics: ingestion, sessions, rollups, goals, alerts, reports.
- Triggers: analytics, event-tracking, funnel, session, alert
- Search first: `src/Models, src/Actions, src/Services, config, docs`
- Related: `filament-signals`, `growth`, `cart`, `checkout`, `orders`, `affiliates`
- Paired: `filament-signals` (Filament admin adapter)

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-signals/CONTEXT.md` when the change crosses UI/domain
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns models, actions, services, events, calculations, and persistence rules.
- If admin UI changes too, audit `filament-signals`.
- Update `docs/*.md` in the same pass when public behavior or config changes.

## Decide fast
- Use when: Tracking or reporting behaviour.
- Skip when: Experiments — see growth; admin UI — see filament-signals.
- Owner/security: Owner-scoped + CrossTenantQuery.

## Key surfaces
- Models: `SavedSignalReport`, `SignalAlertDelivery`, `SignalAlertLog`, `SignalAlertRule`, `SignalDailyMetric`, `SignalEvent`, `SignalGoal`, `SignalIdentity`, `SignalInteractionRule`, `SignalSegment`
- Actions/Services: `Actions/CaptureSignalGeolocation`, `Actions/CaptureSignalPageView`, `Actions/EvaluateAlertRules`, `Actions/IdentifySignalIdentity`, `Actions/IngestSignalEvent`, `Actions/IngestTrustedSignalOutcome`, `Actions/MarkAllSignalAlertsAsRead`, `Actions/MarkSignalAlertAsRead`
- Config `signals.php`: `database`, `table_prefix`, `json_column_type`, `tables`, `tracked_properties`, `identities`, `sessions`, `events`, `interaction_rules`, `daily_metrics`

## Docs map
- Start: `01-overview` → `03-configuration` → `04-usage` → `99-troubleshooting`
- Deep dives: `05-reporting-and-alerts.md`
