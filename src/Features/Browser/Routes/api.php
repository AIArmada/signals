<?php

declare(strict_types=1);

use AIArmada\Signals\Features\Browser\EntryPoints\Http\TrackerScriptController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('signals.http.middleware', ['api']))
    ->prefix(config('signals.http.prefix', 'api/signals'))
    ->group(function (): void {
        Route::get('/' . config('signals.http.tracker_script', 'tracker.js'), TrackerScriptController::class)
            ->name('signals.tracker.script');
    });
