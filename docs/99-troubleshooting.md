---
title: Troubleshooting
---

# Troubleshooting

## No Events Are Ingested

- Confirm `write_key` belongs to an existing `TrackedProperty`.
- Confirm `signals.http.prefix` and `signals.http.middleware` match your app routing.
- Check request validation errors for required fields (`session_identifier`, `path`, `url` for page views).

## The tracker is not auto-injected into HTML responses

- Ensure `signals.integrations.browser.enabled` is `true`.
- Ensure `signals.integrations.browser.auto_inject` is `true`.
- Auto-injection only runs for successful `GET` HTML responses.
- If your HTML already contains a `data-signals-tracker` script tag, Signals will not inject a second copy.

## Tracker Script Loads But No Data

- Ensure script includes `data-write-key`.
- If you rendered the script manually, also include `data-anonymous-id` and `data-session-id`.
- Confirm browser can reach `GET /api/signals/tracker.js`.
- Verify CSP rules allow loading script and posting to ingestion endpoint.

## Browser cookies never appear

- Ensure the `signals.browser` middleware ran for the request that rendered the page.
- If you disabled automatic middleware registration, attach `signals.browser` manually to your web routes or middleware group.
- Check cookie settings under `signals.integrations.browser.identifiers.*` if you are serving across subdomains or HTTPS-only environments.

## Device / Browser Fields Stay Empty

- Ensure `signals.features.ua_parsing.enabled` is true.
- Confirm requests include a real `User-Agent` header.
- Check whether your app is explicitly sending blank device fields from the client, since client values take precedence over parsed values.

## Geolocation Never Captures

- Ensure `signals.features.geolocation.enabled` is true.
- Confirm the rendered tracker script includes `data-enable-geolocation="true"`.
- Verify the browser granted geolocation permission.
- Confirm `POST /api/signals/collect/geo` is reachable through the configured Signals HTTP prefix.

## Cloudflare IP / Country Headers Are Ignored

- `CF-Connecting-IP` and `CF-IPCountry` are honored only when the request arrives through a trusted proxy. Configure Laravel trusted proxies (`TrustProxies` middleware or `Request::setTrustedProxies()`) so the proxy addresses are trusted; otherwise the headers are ignored and the payload values (if any) are used.

## Reverse-Geocoded Fields Stay Null

- Ensure `signals.features.geolocation.reverse_geocode.enabled` is true.
- If `async` is enabled, confirm your queue worker is running so `ReverseGeocodeSessionJob` can complete.
- Check whether the session already has `reverse_geocoded_at`; resolved sessions are skipped on subsequent attempts.

## Revenue / Monetary Fields Are Hidden

- This is expected when `signals.features.monetary.enabled` is false.
- Event and outcome analytics continue to work; only revenue-focused UI and configuration paths are suppressed.

## Missing Commerce Integration Events

- Ensure related packages/events exist.
- Confirm integration toggles are enabled in `signals.integrations.*`.
- Verify expected event names/categories in config if customized.

## Metrics Not Updating

- Run aggregation command manually:

```bash
php artisan signals:aggregate-daily --days=2
```

- Ensure scheduler is running in production.

## Child Writes Fail With an Authorization Error

- `tracked_property_id` is immutable once persisted for identities, sessions, and events. Changing it on an existing row is rejected even for properties of the same owner.
- Referenced properties, sessions, and identities must exist. Events, sessions, and identities reject missing references, and events/sessions reject cross-property session/identity references, even when owner scoping is disabled.
- With `signals.owner.enabled`, every `tracked_property_id`, `signal_session_id`, `signal_identity_id`, and `signal_alert_log_id` is additionally ownership-validated on save. A foreign id is rejected.
- Wrap owner work in `OwnerContext::withOwner($owner, ...)` and global work in `OwnerContext::withOwner(null, ...)`. A missing owner is not the same as global access.

## The Same Idempotency Key Created Two Events

- This is expected when the key was submitted on both ingestion boundaries: browser and trusted keys live in separate namespaces scoped by `ingestion_source`.
- Retries within one boundary still deduplicate. Check the `ingestion_source` column on both rows to confirm which path each came from.

## Alert Rules Never Trigger

- Run dry-run processing:

```bash
php artisan signals:process-alerts --dry-run
```

- Check `is_active`, threshold values, and cooldown windows on `SignalAlertRule`.
- If you expected alerts during ingest, confirm `signals.features.alerts.evaluate_on_ingest.enabled` is turned on. On-ingest alert effects run after the outermost transaction commits; if the outer transaction rolls back, no alert job or log escapes.
- Final delivery failures lock the delivery row inside a transaction and leave `sent` and `dead` rows terminal. Send success and transport-error completion use the same locked reread: a completion that arrives after a terminal transition, or after a newer claim took over, is dropped instead of regressing the row.
- Never mix `sent_at` and `dead_at` on one delivery: exactly one terminal timestamp is ever set. If both appear, the send raced a final failure on an older build; current builds finalize under a row lock.

## Running the Opt-In Engine Concurrency Tests

The `tests/src/Signals/Feature/Engine/` suite exercises real PostgreSQL and MariaDB locking (duplicate ingestion, identity/session races, alert commit boundaries, delivery finalization) with genuine multi-process races. It only runs when you point it at disposable `sigeng_`-prefixed databases; otherwise every engine test skips with the missing variable named. Engine coverage is PostgreSQL read committed and MariaDB repeatable read; Oracle MySQL remains unverified.

```bash
export SIGNALS_ENGINE_PGSQL_DATABASE=sigeng_pg_example
export SIGNALS_ENGINE_PGSQL_HOST=127.0.0.1
export SIGNALS_ENGINE_PGSQL_PORT=5432
export SIGNALS_ENGINE_PGSQL_USER=postgres
export SIGNALS_ENGINE_MYSQL_DATABASE=sigeng_my_example
export SIGNALS_ENGINE_MYSQL_HOST=127.0.0.1
export SIGNALS_ENGINE_MYSQL_PORT=3306
export SIGNALS_ENGINE_MYSQL_USER=root
# Optional when the server is only reachable by socket:
# export SIGNALS_ENGINE_MYSQL_SOCKET=/tmp/mysql.sock

./vendor/bin/pest --parallel tests/src/Signals/Feature/Engine
```

> [!warning]
> Only `sigeng_`-prefixed database names are accepted, and the suite migrates whatever they point at. Never set these variables to an application or shared database.

Keep `app.timezone` at UTC and set the database session timezone in each Laravel connection config, not via process environment or runtime calls:

```php
// config/database.php
'pgsql' => [..., 'timezone' => 'UTC'],
'mysql' => [..., 'timezone' => '+00:00'],
```

Do not use `PGTZ` or `date_default_timezone_set()` for this. The live PostgreSQL server in testing ran `Asia/Kuala_Lumpur`, and only the connection-level session setting kept timestamps comparable; the engine suite asserts the session timezone on both drivers.

When you already own an outer transaction around ingestion or identity work, deadlock retry belongs to that outer `DB::transaction(..., attempts: 5)`. Nested savepoints surface deadlocks to the outer level instead of retrying locally.
