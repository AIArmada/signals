---
title: Signals Context
package: signals
status: current
surface: analytics
family: analytics-and-events
---

# Signals Context

## Snapshot
- Composer: `aiarmada/signals`
- Role: Behavioral analytics foundation with ingestion, sessions, rollups, alerts, and reports.
- Search first: `src/Models`, `src/Actions`, `src/Services`, `src/Jobs`, `config`, `docs`
- Related: `filament-signals`, `growth`, `cart`, `checkout`, `orders`, `affiliates`

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-signals/CONTEXT.md` when admin UI changes are involved
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns ingestion, aggregation, alerting, rollups, and reporting logic.
- Audit paired Filament reporting/admin surfaces when analytics behavior changes.
- Update `docs/*.md` in the same pass when public behavior or config changes.


## Owner-scoped uniqueness

Global and tenant-owned records use a non-null canonical `owner_scope` key for unique business identifiers. Global rows use `global`; owned rows use a stable SHA-256 value derived from the owner morph and primary key. Nullable owner columns are not part of unique constraints, callers cannot mass-assign the scope key, and model saves recompute it from the effective owner tuple. A business key may be reused by different owners but not duplicated globally or within one owner.

## Ingestion trust boundaries

Browser events use `/collect/browser-event` and are bounded, allowlisted, rate-limited, and non-financial. Revenue and transaction/order/conversion identifiers are accepted only by the HMAC-signed `/collect/server-outcome` route. Browser `Origin`/`Referer`/URL matching is a policy check, never authentication.

## Durable alert delivery

Alert evaluation only creates the alert log and one durable delivery per configured destination. `DispatchSignalAlertDelivery` performs email/webhook/Slack delivery on the configured queue with leases, bounded attempts/backoff, safe error codes, HTTP success checks, and per-destination status. Webhook and Slack jobs validate DNS and pin the selected public IP; redirects are disabled.
