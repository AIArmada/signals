---
title: Installation
---

# Installation

## 1. Install Package

```bash
composer require aiarmada/signals
```

## 2. Publish Config (Optional)

```bash
php artisan vendor:publish --tag=signals-config
```

## 3. Run Migrations

```bash
php artisan migrate
```

## 4. Configure a Tracked Property

Create at least one `TrackedProperty` record and keep its `write_key` for ingestion requests.

## 5. Embed Tracker Script (Browser)

```html
<script
    src="/api/signals/tracker.js"
    data-write-key="YOUR_WRITE_KEY"
    defer
></script>
```

If you changed HTTP config (`signals.http.prefix` / `signals.http.tracker_script`), use your configured path.

## 6. Schedule Commands

```php
use Illuminate\Console\Scheduling\Schedule;

protected function schedule(Schedule $schedule): void
{
    $schedule->command('signals:aggregate-daily --days=2')->hourly();
    $schedule->command('signals:process-alerts')->everyFifteenMinutes();
}
```
