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
