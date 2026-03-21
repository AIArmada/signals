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
