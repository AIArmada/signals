---
title: Troubleshooting
---

# Troubleshooting

## No Events Are Ingested

- Confirm `write_key` belongs to an existing `TrackedProperty`.
- Confirm `signals.http.prefix` and `signals.http.middleware` match your app routing.
- Check request validation errors for required fields (`session_identifier`, `path`, `url` for page views).

## Tracker Script Loads But No Data

- Ensure script includes `data-write-key`.
- Confirm browser can reach `GET /api/signals/tracker.js`.
- Verify CSP rules allow loading script and posting to ingestion endpoint.

## Device / Browser Fields Stay Empty

- Ensure `signals.features.ua_parsing.enabled` is true.
- Confirm requests include a real `User-Agent` header.
- Check whether your app is explicitly sending blank device fields from the client, since client values take precedence over parsed values.

## Geolocation Never Captures

- Ensure `signals.features.geolocation.enabled` is true.
- Confirm the rendered tracker script includes `data-enable-geolocation="true"`.
- Verify the browser granted geolocation permission.
- Confirm `POST /api/signals/collect/geo` is reachable through the configured Signals HTTP prefix.

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

## Alert Rules Never Trigger

- Run dry-run processing:

```bash
php artisan signals:process-alerts --dry-run
```

- Check `is_active`, threshold values, and cooldown windows on `SignalAlertRule`.
