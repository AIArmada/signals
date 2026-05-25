---
title: Installation
---

# Installation

## 1. Install the package

```bash
composer require aiarmada/signals
```

## 2. Publish configuration (optional)

```bash
php artisan vendor:publish --tag=signals-config
```

## 3. Run migrations

```bash
php artisan migrate
```

## 4. Configure a tracked property

Create at least one `TrackedProperty` record and keep its `write_key` for ingestion requests.

If you enable integrations such as browser or cart tracking with `tracked_property.auto_create`, Signals can create deterministic tracked properties for the current owner / global context automatically.

## 5. Choose a browser-tracking mode

### Automatic browser integration

Turn on the browser integration if you want Signals to manage browser cookies, tracker injection, and tracked-property lookup for you:

```php
'integrations' => [
    'browser' => [
        'enabled' => true,
    ],
],
```

With the default settings, Signals will:

- register the `signals.browser` middleware alias
- auto-append that middleware to the `web` group
- queue `sig_vid` and `sig_sid` cookies on web responses
- auto-inject the tracker into successful `GET` HTML responses
- auto-create a `commerce-browser` tracked property when needed

### Explicit tracker placement

If you prefer to place the tracker manually, enable the browser integration and render it in Blade:

```blade
@signalsTracker()
```

You can also pass overrides:

```blade
@signalsTracker([
    'enableGeolocation' => true,
    'properties' => [
        'page_type' => 'pricing',
    ],
])
```

Signals only renders tracker markup when browser integration is enabled and it can resolve a tracked property for the current owner / explicit global context.

## 6. Schedule commands

```php
use Illuminate\Console\Scheduling\Schedule;

protected function schedule(Schedule $schedule): void
{
    $schedule->command('signals:aggregate-daily --days=2')->hourly();
    $schedule->command('signals:process-alerts')->everyFifteenMinutes();
}
```

If you enable reverse geocoding with `signals.features.geolocation.reverse_geocode.async = true` or on-ingest alert evaluation with queuing enabled, make sure your queue worker is also running.
