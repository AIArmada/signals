<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lorisleiva\Actions\Concerns\AsAction;

final class ServeSignalsTracker
{
    use AsAction;

    public function asController(Request $request): Response
    {
        $trackerScriptPattern = preg_quote('/' . mb_ltrim((string) config('signals.http.tracker_script', 'tracker.js'), '/'), '/');

        $script = <<<'JS'
(function () {
  var script = document.currentScript;

  if (!script) {
    return;
  }

  var writeKey = script.dataset.writeKey;
  var anonymousId = script.dataset.anonymousId || null;
  var sessionId = script.dataset.sessionId || null;
  var sessionStartedAt = script.dataset.sessionStartedAt || new Date().toISOString();

  if (!writeKey || !anonymousId || !sessionId) {
    console.warn('Signals tracker requires data-write-key, data-anonymous-id, and data-session-id attributes.');
    return;
  }

  var trackerUrl = new URL(script.src, window.location.href);
  var endpoint = script.dataset.endpoint;
  var identifyEndpoint = script.dataset.identifyEndpoint || null;
  var geoEndpoint = script.dataset.geoEndpoint || null;
  var externalId = script.dataset.externalId || null;
  var email = script.dataset.email || null;
  var enableGeolocation = script.dataset.enableGeolocation === 'true';
  var pageProperties = (function () {
    if (!script.dataset.pageProperties) {
      return null;
    }

    try {
      return JSON.parse(script.dataset.pageProperties);
    } catch (error) {
      console.warn('Signals tracker could not parse data-page-properties.');
      return null;
    }
  }());

  if (!endpoint) {
    trackerUrl.pathname = trackerUrl.pathname.replace(/__TRACKER_SCRIPT_PATTERN__$/, '/collect/pageview');
    trackerUrl.search = '';
    trackerUrl.hash = '';
    endpoint = trackerUrl.toString();
  }

  if (!identifyEndpoint) {
    var u = new URL(script.src, window.location.href);
    u.pathname = u.pathname.replace(/__TRACKER_SCRIPT_PATTERN__$/, '/collect/identify');
    u.search = '';
    u.hash = '';
    identifyEndpoint = u.toString();
  }

  if (!geoEndpoint) {
    var geoUrl = new URL(script.src, window.location.href);
    geoUrl.pathname = geoUrl.pathname.replace(/__TRACKER_SCRIPT_PATTERN__$/, '/collect/geo');
    geoUrl.search = '';
    geoUrl.hash = '';
    geoEndpoint = geoUrl.toString();
  }

  var lastUrl = null;

  function payload() {
    var params = new URLSearchParams(window.location.search);
    var properties = pageProperties && typeof pageProperties === 'object'
      ? Object.assign({}, pageProperties)
      : {};

    return {
      write_key: writeKey,
      external_id: externalId,
      anonymous_id: anonymousId,
      email: email,
      session_identifier: sessionId,
      session_started_at: sessionStartedAt,
      occurred_at: new Date().toISOString(),
      path: window.location.pathname + window.location.search + window.location.hash,
      url: window.location.href,
      title: document.title || null,
      referrer: document.referrer || null,
      utm_source: params.get('utm_source'),
      utm_medium: params.get('utm_medium'),
      utm_campaign: params.get('utm_campaign'),
      utm_content: params.get('utm_content'),
      utm_term: params.get('utm_term'),
      properties: Object.keys(properties).length > 0 ? properties : null
    };
  }

  function sendIdentify() {
    if (!identifyEndpoint || !externalId) {
      return;
    }

    var markerKey = 'signals:identified:' + writeKey + ':' + externalId;

    if (sessionStorage.getItem(markerKey) === anonymousId) {
      return;
    }

    sessionStorage.setItem(markerKey, anonymousId);

    var body = JSON.stringify({
      write_key: writeKey,
      external_id: externalId,
      anonymous_id: anonymousId,
      email: email,
      seen_at: new Date().toISOString(),
      url: window.location.href
    });

    if (navigator.sendBeacon && navigator.sendBeacon(identifyEndpoint, new Blob([body], { type: 'application/json' }))) {
      return;
    }

    fetch(identifyEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: body,
      keepalive: true,
      credentials: 'omit'
    }).catch(function () {});
  }

  function sendPageView() {
    if (lastUrl === window.location.href) {
      return;
    }

    lastUrl = window.location.href;

    var body = JSON.stringify(payload());

    if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }))) {
      return;
    }

    fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: body,
      keepalive: true,
      credentials: 'omit'
    }).catch(function () {});
  }

  var originalPushState = history.pushState;
  history.pushState = function () {
    originalPushState.apply(history, arguments);
    setTimeout(sendPageView, 0);
  };

  var originalReplaceState = history.replaceState;
  history.replaceState = function () {
    originalReplaceState.apply(history, arguments);
    setTimeout(sendPageView, 0);
  };

  window.addEventListener('popstate', function () {
    setTimeout(sendPageView, 0);
  });

  function captureGeolocation() {
    if (!enableGeolocation) {
      return;
    }

    if (!navigator.geolocation) {
      return;
    }

    var geoKey = 'signals:geo-captured:' + writeKey;

    if (sessionStorage.getItem(geoKey) === '1') {
      return;
    }

    navigator.geolocation.getCurrentPosition(
      function (position) {
        sessionStorage.setItem(geoKey, '1');

        var body = JSON.stringify({
          write_key: writeKey,
          session_identifier: sessionId,
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy || null
        });

        if (navigator.sendBeacon && navigator.sendBeacon(geoEndpoint, new Blob([body], { type: 'application/json' }))) {
          return;
        }

        fetch(geoEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: body,
          keepalive: true,
          credentials: 'omit'
        }).catch(function () {});
      },
      function () {},
      { timeout: 10000, maximumAge: 300000 }
    );
  }

  sendIdentify();
  sendPageView();
  setTimeout(captureGeolocation, 500);
})();
JS;

        $script = str_replace('__TRACKER_SCRIPT_PATTERN__', $trackerScriptPattern, $script);

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
