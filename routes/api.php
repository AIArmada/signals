<?php

declare(strict_types=1);

use AIArmada\Signals\Actions\CaptureSignalGeolocation;
use AIArmada\Signals\Actions\CaptureSignalPageView;
use AIArmada\Signals\Actions\IdentifySignalIdentity;
use AIArmada\Signals\Actions\IngestSignalEvent;
use AIArmada\Signals\Actions\IngestTrustedSignalOutcome;
use AIArmada\Signals\Actions\ServeSignalsTracker;
use AIArmada\Signals\Http\Middleware\VerifyTrustedSignalSignature;
use Illuminate\Support\Facades\Route;

Route::middleware(config('signals.http.middleware', ['api']))
    ->prefix(config('signals.http.prefix', 'api/signals'))
    ->group(function (): void {
        Route::get('/' . config('signals.http.tracker_script', 'tracker.js'), [ServeSignalsTracker::class, 'asController'])
            ->name('signals.tracker.script');

        Route::post('/collect/identify', [IdentifySignalIdentity::class, 'asController'])
            ->name('signals.collect.identify');

        Route::post('/collect/browser-event', [IngestSignalEvent::class, 'asController'])
            ->name('signals.collect.browser-event');

        Route::post('/collect/pageview', [CaptureSignalPageView::class, 'asController'])
            ->name('signals.collect.pageview');

        Route::post('/collect/geo', [CaptureSignalGeolocation::class, 'asController'])
            ->name('signals.collect.geo');

        Route::post('/collect/server-outcome', [IngestTrustedSignalOutcome::class, 'asController'])
            ->middleware(VerifyTrustedSignalSignature::class)
            ->name('signals.collect.server-outcome');
    });
