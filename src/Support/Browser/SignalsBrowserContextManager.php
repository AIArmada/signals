<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support\Browser;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SignalsBrowserContextManager
{
    public const string CONTEXT_ATTRIBUTE = 'signals.browser.context';

    public const string TRACKER_RENDERED_ATTRIBUTE = 'signals.browser.tracker_rendered';

    public function current(?Request $request = null): ?SignalsBrowserContext
    {
        $request ??= $this->request();

        if (! $request instanceof Request) {
            return null;
        }

        $context = $request->attributes->get(self::CONTEXT_ATTRIBUTE);

        return $context instanceof SignalsBrowserContext ? $context : null;
    }

    public function resolveOrCreate(Request $request): SignalsBrowserContext
    {
        $current = $this->current($request);

        if ($current instanceof SignalsBrowserContext) {
            return $current;
        }

        $visitorId = $this->normalizeIdentifier($request->cookies->get($this->visitorCookieName()));
        $sessionId = $this->normalizeIdentifier($request->cookies->get($this->sessionCookieName()));
        $visitorWasGenerated = $visitorId === null;
        $sessionWasGenerated = $sessionId === null;

        $context = new SignalsBrowserContext(
            visitorId: $visitorId ?? $this->generateVisitorId(),
            sessionId: $sessionId ?? $this->generateSessionId(),
            sessionStartedAt: $sessionWasGenerated ? CarbonImmutable::now()->toIso8601String() : null,
            visitorWasGenerated: $visitorWasGenerated,
            sessionWasGenerated: $sessionWasGenerated,
        );

        $this->store($request, $context);

        return $context;
    }

    public function store(Request $request, SignalsBrowserContext $context): void
    {
        $request->attributes->set(self::CONTEXT_ATTRIBUTE, $context);
    }

    public function wasTrackerRendered(?Request $request = null): bool
    {
        $request ??= $this->request();

        if (! $request instanceof Request) {
            return false;
        }

        return (bool) $request->attributes->get(self::TRACKER_RENDERED_ATTRIBUTE, false);
    }

    public function markTrackerRendered(?Request $request = null): void
    {
        $request ??= $this->request();

        if (! $request instanceof Request) {
            return;
        }

        $request->attributes->set(self::TRACKER_RENDERED_ATTRIBUTE, true);
    }

    public function queueCookies(Response $response, SignalsBrowserContext $context): void
    {
        $response->headers->setCookie(
            $this->makeCookie(
                name: $this->visitorCookieName(),
                value: $context->visitorId,
                minutes: $this->visitorCookieTtlMinutes(),
            ),
        );

        $response->headers->setCookie(
            $this->makeCookie(
                name: $this->sessionCookieName(),
                value: $context->sessionId,
                minutes: $this->sessionCookieTtlMinutes(),
            ),
        );
    }

    public function visitorCookieName(): string
    {
        return $this->stringConfig('signals.integrations.browser.identifiers.visitor_cookie_name', 'sig_vid');
    }

    public function sessionCookieName(): string
    {
        return $this->stringConfig('signals.integrations.browser.identifiers.session_cookie_name', 'sig_sid');
    }

    private function visitorCookieTtlMinutes(): int
    {
        return $this->secondsToMinutes(
            (int) config('signals.integrations.browser.identifiers.visitor_cookie_ttl_seconds', 31_536_000),
        );
    }

    private function sessionCookieTtlMinutes(): int
    {
        return $this->secondsToMinutes(
            (int) config(
                'signals.integrations.browser.identifiers.session_cookie_ttl_seconds',
                (int) config('signals.defaults.session_duration_seconds', 1_800),
            ),
        );
    }

    private function secondsToMinutes(int $seconds): int
    {
        return max(1, (int) ceil(max(1, $seconds) / 60));
    }

    private function makeCookie(string $name, string $value, int $minutes): Cookie
    {
        /** @var Cookie $cookie */
        $cookie = cookie(
            name: $name,
            value: $value,
            minutes: $minutes,
            path: $this->stringConfig('signals.integrations.browser.identifiers.path', '/'),
            domain: $this->nullableStringConfig('signals.integrations.browser.identifiers.domain'),
            secure: config('signals.integrations.browser.identifiers.secure'),
            httpOnly: (bool) config('signals.integrations.browser.identifiers.http_only', true),
            raw: false,
            sameSite: $this->nullableStringConfig('signals.integrations.browser.identifiers.same_site', 'lax'),
        );

        return $cookie;
    }

    private function generateVisitorId(): string
    {
        return 'sigv_' . Str::lower((string) Str::ulid());
    }

    private function generateSessionId(): string
    {
        return 'sigs_' . Str::lower((string) Str::ulid());
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = mb_trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function request(): ?Request
    {
        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->nullableStringConfig($key, $default);

        return $value ?? $default;
    }

    private function nullableStringConfig(string $key, ?string $default = null): ?string
    {
        $value = config($key, $default);

        if (! is_string($value)) {
            return $default;
        }

        $normalized = mb_trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
