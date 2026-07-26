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
  var eventEndpoint = script.dataset.eventEndpoint || null;
  var identifyEndpoint = script.dataset.identifyEndpoint || null;
  var geoEndpoint = script.dataset.geoEndpoint || null;
  var externalId = script.dataset.externalId || null;
  var email = script.dataset.email || null;
  var enableGeolocation = script.dataset.enableGeolocation === 'true';
  var inlineTrackingEnabled = script.dataset.inlineTracking !== 'false';
  var interactionRules = (function () {
    if (!script.dataset.interactionRules) {
      return [];
    }

    try {
      var parsed = JSON.parse(script.dataset.interactionRules);

      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      console.warn('Signals tracker could not parse data-interaction-rules.');
      return [];
    }
  }());
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

  if (!eventEndpoint) {
    var eventUrl = new URL(script.src, window.location.href);
    eventUrl.pathname = eventUrl.pathname.replace(/__TRACKER_SCRIPT_PATTERN__$/, '/collect/browser-event');
    eventUrl.search = '';
    eventUrl.hash = '';
    eventEndpoint = eventUrl.toString();
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

  function emit(endpointUrl, data) {
    if (!endpointUrl) {
      return;
    }

    var body = JSON.stringify(data);

    if (navigator.sendBeacon && navigator.sendBeacon(endpointUrl, new Blob([body], { type: 'application/json' }))) {
      return;
    }

    fetch(endpointUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: body,
      keepalive: true,
      credentials: 'omit'
    }).catch(function () {});
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

    emit(identifyEndpoint, {
      write_key: writeKey,
      external_id: externalId,
      anonymous_id: anonymousId,
      email: email,
      seen_at: new Date().toISOString(),
      url: window.location.href
    });
  }

  function sendPageView() {
    if (lastUrl === window.location.href) {
      return;
    }

    lastUrl = window.location.href;

    emit(endpoint, payload());
  }

  function currentTrackingContext() {
    var params = new URLSearchParams(window.location.search);

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
      referrer: document.referrer || null,
      utm_source: params.get('utm_source'),
      utm_medium: params.get('utm_medium'),
      utm_campaign: params.get('utm_campaign'),
      utm_content: params.get('utm_content'),
      utm_term: params.get('utm_term')
    };
  }

  function normalizeText(value) {
    if (!value) {
      return null;
    }

    return String(value).replace(/\s+/g, ' ').trim() || null;
  }

  function eventProperties(rule, element, extraProperties) {
    var properties = pageProperties && typeof pageProperties === 'object'
      ? Object.assign({}, pageProperties)
      : {};

    properties.interaction_rule_id = rule.id || null;
    properties.interaction_rule_slug = rule.slug || null;
    properties.interaction_rule_name = rule.name || null;
    properties.interaction_trigger_type = rule.trigger_type || null;
    properties.interaction_selector = rule.selector || null;

    if (element && element.tagName) {
      properties.element_tag = String(element.tagName).toLowerCase();
      properties.element_id = element.id || null;
      properties.element_class = normalizeText(element.className);
      properties.element_text = normalizeText(element.innerText || element.textContent);
      properties.element_href = element.href || null;
    }

    if (extraProperties && typeof extraProperties === 'object') {
      Object.keys(extraProperties).forEach(function (key) {
        properties[key] = extraProperties[key];
      });
    }

    return properties;
  }

  function matchesPage(rule) {
    var pattern = rule.page_pattern;

    if (!pattern) {
      return true;
    }

    var path = window.location.pathname;
    var escaped = String(pattern)
      .replace(/[.+^${}()|[\]\\]/g, '\\$&')
      .replace(/\*/g, '.*');
    var regex = new RegExp('^' + escaped + '$');

    return regex.test(path);
  }

  function shouldTrackRule(rule) {
    return !!rule && !!rule.event_name && matchesPage(rule);
  }

  function shouldTrackOncePerSession(rule, marker) {
    var settings = rule.settings && typeof rule.settings === 'object' ? rule.settings : {};

    if (settings.once_per_session !== true) {
      return true;
    }

    var key = 'signals:rule:' + (rule.id || rule.slug || rule.event_name) + ':' + marker;

    if (sessionStorage.getItem(key) === '1') {
      return false;
    }

    sessionStorage.setItem(key, '1');

    return true;
  }

  function trackInteraction(rule, element, extraProperties) {
    if (!shouldTrackRule(rule)) {
      return;
    }

    var marker = (extraProperties && extraProperties.action) || 'default';

    if (!shouldTrackOncePerSession(rule, marker)) {
      return;
    }

    var context = currentTrackingContext();

    emit(eventEndpoint, {
      write_key: context.write_key,
      event_name: rule.event_name,
      event_category: rule.event_category || 'engagement',
      external_id: context.external_id,
      anonymous_id: context.anonymous_id,
      email: context.email,
      session_identifier: context.session_identifier,
      session_started_at: context.session_started_at,
      occurred_at: context.occurred_at,
      path: context.path,
      url: context.url,
      referrer: context.referrer,
      utm_source: context.utm_source,
      utm_medium: context.utm_medium,
      utm_campaign: context.utm_campaign,
      utm_content: context.utm_content,
      utm_term: context.utm_term,
      properties: eventProperties(rule, element, extraProperties)
    });
  }

  function safeClosest(target, selector) {
    if (!target || !selector || typeof target.closest !== 'function') {
      return null;
    }

    try {
      return target.closest(selector);
    } catch (error) {
      return null;
    }
  }

  function installClickRules(rules) {
    if (!rules.length) {
      return;
    }

    document.addEventListener('click', function (event) {
      rules.forEach(function (rule) {
        var matched = safeClosest(event.target, rule.selector || '');

        if (!matched) {
          return;
        }

        trackInteraction(rule, matched, {
          action: 'click'
        });
      });
    }, true);
  }

  function expandedStateForAccordion(element) {
    if (!element) {
      return null;
    }

    var detailsHost = element.closest('details');

    if (detailsHost) {
      return detailsHost.open ? 'open' : 'closed';
    }

    var expanded = element.getAttribute('aria-expanded');

    if (expanded === 'true') {
      return 'open';
    }

    if (expanded === 'false') {
      return 'closed';
    }

    return null;
  }

  function installAccordionRules(rules) {
    if (!rules.length) {
      return;
    }

    document.addEventListener('click', function (event) {
      rules.forEach(function (rule) {
        var matched = safeClosest(event.target, rule.selector || '');

        if (!matched) {
          return;
        }

        setTimeout(function () {
          trackInteraction(rule, matched, {
            action: 'accordion_toggle',
            accordion_state: expandedStateForAccordion(matched)
          });
        }, 0);
      });
    }, true);
  }

  function installMediaRules(rules) {
    if (!rules.length) {
      return;
    }

    ['play', 'pause', 'ended'].forEach(function (eventName) {
      document.addEventListener(eventName, function (event) {
        rules.forEach(function (rule) {
          var mediaElement = safeClosest(event.target, rule.selector || 'audio,video');

          if (!mediaElement) {
            return;
          }

          trackInteraction(rule, mediaElement, {
            action: eventName,
            media_current_time: typeof mediaElement.currentTime === 'number' ? mediaElement.currentTime : null,
            media_duration: typeof mediaElement.duration === 'number' && isFinite(mediaElement.duration) ? mediaElement.duration : null
          });
        });
      }, true);
    });
  }

  function installYoutubeRules(rules) {
    if (!rules.length) {
      return;
    }

    document.addEventListener('click', function (event) {
      rules.forEach(function (rule) {
        var matched = safeClosest(event.target, rule.selector || '');

        if (!matched) {
          return;
        }

        trackInteraction(rule, matched, {
          action: 'youtube_click'
        });
      });
    }, true);
  }

  function installInteractionTracking() {
    if (!Array.isArray(interactionRules) || !interactionRules.length || !eventEndpoint) {
      return;
    }

    var clickRules = interactionRules.filter(function (rule) {
      return rule.trigger_type === 'click' && !!rule.selector;
    });
    var accordionRules = interactionRules.filter(function (rule) {
      return rule.trigger_type === 'accordion' && !!rule.selector;
    });
    var mediaRules = interactionRules.filter(function (rule) {
      return rule.trigger_type === 'media';
    });
    var youtubeRules = interactionRules.filter(function (rule) {
      return rule.trigger_type === 'youtube' && !!rule.selector;
    });

    installClickRules(clickRules);
    installAccordionRules(accordionRules);
    installMediaRules(mediaRules);
    installYoutubeRules(youtubeRules);
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

        emit(geoEndpoint, {
          write_key: writeKey,
          session_identifier: sessionId,
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy || null
        });
      },
      function () {},
      { timeout: 10000, maximumAge: 300000 }
    );
  }

  function browserPlatform() {
    var ua = navigator.userAgent.toLowerCase();

    if (ua.indexOf('ipad') !== -1) { return 'ipados'; }
    if (ua.indexOf('iphone') !== -1 || ua.indexOf('ipod') !== -1) { return 'ios'; }
    if (ua.indexOf('android') !== -1) { return 'android'; }
    if (ua.indexOf('macintosh') !== -1 || ua.indexOf('mac os') !== -1) { return 'macos'; }
    if (ua.indexOf('windows') !== -1) { return 'windows'; }
    if (ua.indexOf('linux') !== -1) { return 'linux'; }

    return 'web';
  }

  function browserFamily(platform) {
    return platform === 'ios' || platform === 'android' || platform === 'ipados' ? 'mobile'
      : platform === 'macos' || platform === 'windows' || platform === 'linux' ? 'desktop'
      : 'web';
  }

  function parseInlineProps(raw) {
    if (!raw) { return {}; }

    try {
      var parsed = JSON.parse(raw);

      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function inlineLabel(element) {
    if (!element) { return null; }

    if (element.dataset.signalLabel) {
      return normalizeText(element.dataset.signalLabel);
    }

    if (element.getAttribute('aria-label')) {
      return normalizeText(element.getAttribute('aria-label'));
    }

    if (element.getAttribute('title')) {
      return normalizeText(element.getAttribute('title'));
    }

    return normalizeText(element.textContent);
  }

  function inlineEventPayload(name, category, element, extraProperties) {
    var platform = browserPlatform();
    var props = parseInlineProps(element.dataset.signalProps);
    var signalComponent = element.dataset.signalComponent
      || (element.closest && element.closest('[data-signal-component]') ? (element.closest('[data-signal-component]') || {}).dataset.signalComponent : null)
      || null;
    var signalControl = element.dataset.signalControl || element.name || element.id || null;
    var signalEntityType = element.dataset.signalEntityType || null;
    var signalEntityId = element.dataset.signalEntityId || null;

    props.surface = (pageProperties && pageProperties.surface) || 'public';
    props.page = window.location.pathname;
    props.client_origin = 'web';
    props.client_origin_source = 'browser_tracker';
    props.client_platform = platform;
    props.client_family = browserFamily(platform);
    props.client_transport = 'web';
    props.component = signalComponent;
    props.control = signalControl;
    props.label = inlineLabel(element);
    props.entity_type = signalEntityType;
    props.entity_id = signalEntityId;

    if (element instanceof HTMLAnchorElement) {
      props.href = element.href;
    }

    if (extraProperties && typeof extraProperties === 'object') {
      Object.keys(extraProperties).forEach(function (k) {
        props[k] = extraProperties[k];
      });
    }

    var context = currentTrackingContext();

    return {
      write_key: context.write_key,
      event_name: name,
      event_category: category || name.split('.')[0] || 'ui',
      external_id: context.external_id,
      anonymous_id: context.anonymous_id,
      email: context.email,
      session_identifier: context.session_identifier,
      session_started_at: context.session_started_at,
      occurred_at: context.occurred_at,
      path: context.path,
      url: context.url,
      referrer: context.referrer,
      utm_source: context.utm_source,
      utm_medium: context.utm_medium,
      utm_campaign: context.utm_campaign,
      utm_content: context.utm_content,
      utm_term: context.utm_term,
      properties: props
    };
  }

  function installInlineAttributeTracking() {
    if (!inlineTrackingEnabled || !eventEndpoint) { return; }

    document.addEventListener('click', function (event) {
      var trigger = event.target instanceof Element
        ? event.target.closest('[data-signal-event]')
        : null;

      if (!trigger || trigger.matches('[disabled], [aria-disabled="true"]')) {
        return;
      }

      emit(eventEndpoint, inlineEventPayload(
        trigger.dataset.signalEvent,
        trigger.dataset.signalCategory || null,
        trigger
      ));
    }, true);

    document.addEventListener('submit', function (event) {
      var form = event.target instanceof HTMLFormElement ? event.target : null;

      if (!form || !form.dataset.signalSubmitEvent) {
        return;
      }

      emit(eventEndpoint, inlineEventPayload(
        form.dataset.signalSubmitEvent,
        form.dataset.signalCategory || null,
        form
      ));
    }, true);

    document.addEventListener('change', function (event) {
      var target = event.target instanceof HTMLElement ? event.target : null;

      if (!target) { return; }

      var trigger = target.closest ? target.closest('[data-signal-change-event]') : null;

      if (!trigger) { return; }

      var includeValue = target.dataset.signalIncludeValue === 'true'
        || (trigger.dataset.signalIncludeValue === 'true');

      emit(eventEndpoint, inlineEventPayload(
        trigger.dataset.signalChangeEvent,
        trigger.dataset.signalCategory || null,
        trigger,
        {
          field_name: target.getAttribute('name') || target.id || null,
          field_type: target.getAttribute('type') || target.tagName.toLowerCase(),
          field_value: includeValue ? (target.type === 'checkbox' || target.type === 'radio')
            ? (target.checked ? target.value : null)
            : target.value || null
            : null
        }
      ));
    }, true);
  }

  sendIdentify();
  sendPageView();
  installInteractionTracking();
  installInlineAttributeTracking();
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
