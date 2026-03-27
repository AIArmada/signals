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

  if (!writeKey) {
    console.warn('Signals tracker requires a data-write-key attribute.');
    return;
  }

  var trackerUrl = new URL(script.src, window.location.href);
  var endpoint = script.dataset.endpoint;
  var identifyEndpoint = script.dataset.identifyEndpoint || null;
  var anonymousCookieName = script.dataset.anonymousCookieName || 'mi_signals_anonymous_id';
  var sessionCookieName = script.dataset.sessionCookieName || 'mi_signals_session_id';
  var externalId = script.dataset.externalId || null;
  var email = script.dataset.email || null;
  var enableGeolocation = script.dataset.enableGeolocation === 'true';

  if (!endpoint) {
    trackerUrl.pathname = trackerUrl.pathname.replace(/__TRACKER_SCRIPT_PATTERN__$/, '/collect/pageview');
    trackerUrl.search = '';
    trackerUrl.hash = '';
    endpoint = trackerUrl.toString();
  }

  var geoEndpoint = (function () {
    var u = new URL(script.src, window.location.href);
    u.pathname = u.pathname.replace(/__TRACKER_SCRIPT_PATTERN__$/, '/collect/geo');
    u.search = '';
    u.hash = '';
    return u.toString();
  }());

  var sessionKey = 'signals:session:' + writeKey;
  var anonymousKey = 'signals:anonymous:' + writeKey;
  var startedAtKey = 'signals:session-started-at:' + writeKey;
  var lastUrl = null;

  function readCookie(name) {
    var prefix = name + '=';
    var parts = document.cookie ? document.cookie.split(';') : [];

    for (var index = 0; index < parts.length; index++) {
      var cookie = parts[index].trim();

      if (cookie.indexOf(prefix) === 0) {
        return decodeURIComponent(cookie.slice(prefix.length));
      }
    }

    return null;
  }

  function writeCookie(name, value, maxAgeSeconds) {
    var cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';

    if (typeof maxAgeSeconds === 'number') {
      cookie += '; Max-Age=' + maxAgeSeconds;
    }

    if (window.location.protocol === 'https:') {
      cookie += '; Secure';
    }

    document.cookie = cookie;
  }

  function anonymousIdentifier() {
    var existing = localStorage.getItem(anonymousKey) || readCookie(anonymousCookieName);

    if (existing) {
      localStorage.setItem(anonymousKey, existing);
      writeCookie(anonymousCookieName, existing, 31536000);

      return existing;
    }

    var created = 'sig_anon_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem(anonymousKey, created);
    writeCookie(anonymousCookieName, created, 31536000);

    return created;
  }

  function sessionIdentifier() {
    var existing = sessionStorage.getItem(sessionKey);

    if (existing) {
      writeCookie(sessionCookieName, existing);

      return existing;
    }

    var created = 'sig_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    sessionStorage.setItem(sessionKey, created);
    sessionStorage.setItem(startedAtKey, new Date().toISOString());
    writeCookie(sessionCookieName, created);

    return created;
  }

  function sessionStartedAt() {
    var value = sessionStorage.getItem(startedAtKey);

    if (value) {
      return value;
    }

    var created = new Date().toISOString();
    sessionStorage.setItem(startedAtKey, created);

    return created;
  }

  function payload() {
    var params = new URLSearchParams(window.location.search);

    return {
      write_key: writeKey,
      anonymous_id: anonymousIdentifier(),
      session_identifier: sessionIdentifier(),
      session_started_at: sessionStartedAt(),
      occurred_at: new Date().toISOString(),
      path: window.location.pathname + window.location.search + window.location.hash,
      url: window.location.href,
      title: document.title || null,
      referrer: document.referrer || null,
      utm_source: params.get('utm_source'),
      utm_medium: params.get('utm_medium'),
      utm_campaign: params.get('utm_campaign'),
      utm_content: params.get('utm_content'),
      utm_term: params.get('utm_term')
    };
  }

  function sendIdentify() {
    if (!identifyEndpoint || !externalId) {
      return;
    }

    var markerKey = 'signals:identified:' + writeKey + ':' + externalId;
    var anonymousId = anonymousIdentifier();

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

    if (navigator.sendBeacon) {
      navigator.sendBeacon(identifyEndpoint, new Blob([body], { type: 'application/json' }));
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

    if (navigator.sendBeacon) {
      navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
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
          session_identifier: sessionIdentifier(),
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy || null
        });

        if (navigator.sendBeacon) {
          navigator.sendBeacon(geoEndpoint, new Blob([body], { type: 'application/json' }));
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
