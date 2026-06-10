# Signals Package Lifecycle Audit & Refactoring Plan

## 1. Executive Summary

The Signals package (11 tables, 11 models) is a behavioral analytics foundation. It stores ingestion events, sessions, identities, daily rollups, and configuration entities (properties, segments, reports, alerts, goals, interaction rules). The schema is Postgres-native (timestampTz, jsonb) and multitenant (nullableMorphs owner).

The config entities (`signal_tracked_properties`, `signal_segments`, `signal_saved_reports`, `signal_alert_rules`, `signal_goals`, `signal_interaction_rules`) use `is_active` booleans — these are **admin configuration toggles** and do not require lifecycle changes. The real lifecycle issues are limited to `signal_sessions` (boolean event flags that should be timestamps) and `signal_alert_logs` (duplicate boolean+timestamp).

**Core violations (narrow scope):**
- `is_read` boolean on `alert_logs` (already has `read_at`) → remove boolean, infer from timestamp
- `is_bounce` boolean on `sessions` → convert to `bounced_at` timestampTz
- `is_bot` boolean on `sessions` → convert to `identified_as_bot_at` timestampTz

**No changes needed for 6 configuration tables.** Their `is_active` booleans are admin toggles, not lifecycle concerns.

---

## 2. Full Inventory by Table

### 2.1 `signal_tracked_properties`
| Column | Type | Current | Notes |
|--------|------|---------|-------|
| All columns | — | — | Config entity. `is_active` boolean is an admin config toggle — kept as-is. |
| `created_at` / `updated_at` | timestampTz | OK | — |

### 2.2 `signal_identities`
| Column | Type | Current | Notes |
|--------|------|---------|-------|
| All columns | — | Clean | Keeps `first_seen_at` + `last_seen_at` timestamps. No issues. |

### 2.3 `signal_sessions`
| Column | Type | Current | Problem |
|--------|------|---------|---------|
| **`is_bounce`** | boolean default false | Event flag | Convert to `bounced_at` timestampTz nullable |
| **`is_bot`** | boolean default false | Event flag | Convert to `identified_as_bot_at` timestampTz nullable |
| All other columns | — | Clean | — |

### 2.4 `signal_events`
| Column | Type | Notes |
|--------|------|-------|
| All columns | — | Clean. No `is_*` booleans. |

### 2.5 `signal_daily_metrics`
| Column | Type | Notes |
|--------|------|-------|
| All columns | — | Clean. No `is_*` booleans. |

### 2.6 `signal_segments`
| Column | Type | Notes |
|--------|------|-------|
| All columns | — | Config entity. `is_active` boolean — kept as-is. |

### 2.7 `signal_saved_reports`
| Column | Type | Notes |
|--------|------|-------|
| All columns | — | Config entity. `is_active` boolean, `is_shared` boolean — kept as-is. |

### 2.8 `signal_alert_rules`
| Column | Type | Notes |
|--------|------|-------|
| All columns | — | Config entity. `is_active` boolean — kept as-is. `last_triggered_at` already a timestamp. |

### 2.9 `signal_alert_logs`
| Column | Type | Current | Problem |
|--------|------|---------|---------|
| **`is_read`** | boolean default false | Duplicate | Remove boolean; `read_at` nullable already exists — infer read status from it |
| All other columns | — | Clean | — |

### 2.10 `signal_goals`
| Column | Type | Notes |
|--------|------|-------|
| All columns | — | Config entity. `is_active` boolean — kept as-is. |

### 2.11 `signal_interaction_rules`
| Column | Type | Notes |
|--------|------|-------|
| All columns | — | Config entity. `is_active` boolean — kept as-is. |

---

## 3. Problems Summary

| # | Problem | Tables Affected | Severity |
|---|---------|----------------|----------|
| P1 | `is_read` boolean duplicates `read_at` timestamp | `signal_alert_logs` | Medium |
| P2 | `is_bounce` boolean instead of `bounced_at` timestamp | `signal_sessions` | Medium |
| P3 | `is_bot` boolean instead of `identified_as_bot_at` timestamp | `signal_sessions` | Medium |

**Not problems (config entities):** `is_active` booleans on `signal_tracked_properties`, `signal_segments`, `signal_saved_reports`, `signal_alert_rules`, `signal_goals`, `signal_interaction_rules` are admin configuration toggles and do not require lifecycle state machines, `status` columns, or `archived_at` timestamps. `is_shared` on `saved_reports` is a sharing designation, not a lifecycle state.

---

## 4. Recommended Structure

### 4.1 Column Changes Summary

| Table | Remove | Add |
|-------|--------|-----|
| `signal_alert_logs` | `is_read` | _(nothing — use existing `read_at`)_ |
| `signal_sessions` | `is_bounce`, `is_bot` | `bounced_at` timestampTz nullable, `identified_as_bot_at` timestampTz nullable |

All other tables: no changes.

### 4.2 Model Changes

**SignalAlertLog:**
- Remove `is_read` from `$fillable`, `$casts`, `getAuditInclude()`
- Replace `$log->is_read = true` + `$log->read_at = now()` with just `$log->read_at = now()`
- Add accessor: `isRead(): bool` → `return $this->read_at !== null`

**SignalSession:**
- Remove `is_bounce`, `is_bot` from `$fillable`, `$casts`
- Add `bounced_at`, `identified_as_bot_at` to `$casts` as `immutable_datetime`
- Replace `$session->is_bounce = true` with `$session->bounced_at = now()`
- Replace `$session->is_bot = true` with `$session->identified_as_bot_at = now()`
- Add accessors: `isBounce(): bool` → `return $this->bounced_at !== null`, `isBot(): bool` → `return $this->identified_as_bot_at !== null`

### 4.3 Index Updates

- `signal_alert_logs`: `$table->index(['signal_alert_rule_id', 'is_read'])` → remove or replace with `$table->index(['signal_alert_rule_id', 'read_at'])`
- `signal_sessions`: No index changes needed (no `is_*` in indexes)

---

## 5. Refactoring Plan — Parallel-Agent Checklist

### Phase 1 — Migrations (parallel)

- [x] **M1**: Rewrite `signal_alert_logs` migration — drop `is_read`
- [x] **M2**: Rewrite `signal_sessions` migration — drop `is_bounce` + `is_bot`, add `bounced_at` + `identified_as_bot_at`

### Phase 2 — Models (parallel)

- [x] **D1**: Update `SignalAlertLog` model — drop `is_read`, add `isRead()` accessor
- [x] **D2**: Update `SignalSession` model — drop `is_bounce`/`is_bot`, add `bounced_at`/`identified_as_bot_at` casts, add `isBounce()`/`isBot()` accessors

### Phase 3 — Downstream Sweep

- [x] Grep & update all references in `packages/signals/src/` (Actions, Services, Jobs) for `is_read`, `is_bounce`, `is_bot`
- [x] Update `filament-signals` package references
- [x] Run full test suite: `./vendor/bin/pest --parallel packages/signals/tests`
- [x] Run PHPStan: `./vendor/bin/phpstan analyse packages/signals/src --level=6`
- [x] Run Pint on changed files only

---

## 6. Migration Strategy

**No backward compatibility.** Fresh migrations replace old ones.

### Affected migrations only

| # | Table | Change |
|---|-------|--------|
| 1 | `signal_sessions` | Drop `is_bounce`, `is_bot`; add `bounced_at`, `identified_as_bot_at` |
| 2 | `signal_alert_logs` | Drop `is_read` |

All other migrations (9 tables) remain unchanged.

### New column SQL patterns

```sql
-- For sessions:
bounced_at timestamptz null
identified_as_bot_at timestamptz null

-- Column removal:
-- DROP is_read (alert_logs)
-- DROP is_bounce (sessions)
-- DROP is_bot (sessions)
```

---

## 7. Verification Commands

### Schema verification
```bash
# Verify is_read removed from migrations
rg -n "is_read" packages/signals/database/migrations/

# Verify is_bounce, is_bot removed from migrations
rg -n "is_bounce|is_bot" packages/signals/database/migrations/

# Verify bounced_at + identified_as_bot_at in sessions migration
rg -n "bounced_at|identified_as_bot_at" packages/signals/database/migrations/

# Verify no FK constraints slipped in
rg -n "constrained\(|cascadeOnDelete\(" packages/signals/database/migrations/
```

### Model verification
```bash
# Verify no is_read references in SignalAlertLog
rg -n "is_read" packages/signals/src/Models/SignalAlertLog.php

# Verify is_read → read_at usage
rg -n "read_at" packages/signals/src/Models/SignalAlertLog.php

# Verify is_bounce/is_bot removed from SignalSession
rg -n "is_bounce|is_bot" packages/signals/src/Models/SignalSession.php
```

### Source sweep
```bash
rg -n "is_read|is_bounce|is_bot" packages/signals/src/
```

### Static analysis
```bash
./vendor/bin/phpstan analyse packages/signals/src --level=6
```

### Testing
```bash
# Full signals test suite
./vendor/bin/pest --parallel packages/signals/tests/

# With coverage
./vendor/bin/pest --coverage --parallel packages/signals/tests/ 2>&1 | tee /tmp/signals-coverage.txt
```

### Formatting
```bash
./vendor/bin/pint packages/signals/src packages/signals/database packages/signals/config
```
