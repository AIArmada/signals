<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\EntryPoints\Http;

use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use AIArmada\Signals\Features\Ingestion\Support\BrowserPayloadPolicy;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SignalsIngestionRequestValidator
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly BrowserPayloadPolicy $payloadPolicy,
    ) {}

    public function resolveTrackedProperty(Request $request, string $writeKey): TrackedProperty
    {
        $trackedProperty = $this->findActiveProperty($writeKey);

        // Domain matching is a browser policy check only. It is intentionally not
        // treated as authentication because Origin and Referer are spoofable.
        $this->assertRequestMatchesTrackedPropertyDomain($request, $trackedProperty);

        return $trackedProperty;
    }

    public function resolveTrustedProperty(string $writeKey): TrackedProperty
    {
        return $this->findActiveProperty($writeKey);
    }

    public function assertBrowserPayload(Request $request, string $writeKey, string $eventName): void
    {
        $this->assertPublicPayload($request, $writeKey, $eventName);
    }

    public function assertPublicPayload(Request $request, string $writeKey, ?string $eventName = null): void
    {
        $this->assertWriteKeyNotInQueryString($request);
        $this->assertPayloadWithinLimits($request, 'browser');
        if ($eventName === null) {
            $this->payloadPolicy->assertNoTrustedFields($request->all());
        } else {
            $this->payloadPolicy->assertEvent($eventName, $request->all());
        }

        $this->assertRateLimit(
            key: 'signals:browser:property:' . hash('sha256', $writeKey),
            attempts: max(1, (int) config('signals.ingestion.browser.property_rate_limit_per_minute', 120)),
        );
        $this->assertRateLimit(
            key: 'signals:browser:ip:' . ($request->ip() ?? 'unknown'),
            attempts: max(1, (int) config('signals.ingestion.browser.rate_limit_per_minute', 120)),
        );
    }

    public function assertTrustedPayloadWithinLimits(Request $request): void
    {
        $this->assertWriteKeyNotInQueryString($request);
        $this->assertPayloadWithinLimits($request, 'trusted');
        $this->assertRateLimit(
            key: 'signals:trusted:' . ($request->ip() ?? 'unknown'),
            attempts: max(1, (int) config('signals.ingestion.trusted.rate_limit_per_minute', 60)),
        );
    }

    private function findActiveProperty(string $writeKey): TrackedProperty
    {
        return TrackedProperty::query()
            ->withoutOwnerScope()
            ->where('write_key', $writeKey)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function assertWriteKeyNotInQueryString(Request $request): void
    {
        if ($request->query('write_key') !== null) {
            throw ValidationException::withMessages([
                'write_key' => 'The write key must be sent in the request body, not the query string.',
            ]);
        }
    }

    private function assertPayloadWithinLimits(Request $request, string $boundary): void
    {
        $maxBytes = max(1024, (int) config("signals.ingestion.{$boundary}.max_bytes", 32768));

        $content = $request->getContent();

        if (is_string($content) && mb_strlen($content, '8bit') > $maxBytes) {
            throw ValidationException::withMessages([
                'payload' => "The {$boundary} Signals payload exceeds {$maxBytes} bytes.",
            ]);
        }

        $this->payloadPolicy->assertStructure($request->all(), $boundary);
    }

    private function assertRateLimit(string $key, int $attempts): void
    {
        if ($this->rateLimiter->tooManyAttempts($key, $attempts)) {
            throw new HttpException(429, 'Signals ingestion rate limit exceeded.');
        }

        $this->rateLimiter->hit($key, 60);
    }

    private function assertRequestMatchesTrackedPropertyDomain(Request $request, TrackedProperty $trackedProperty): void
    {
        $configuredDomain = $this->normalizeHost($trackedProperty->domain);

        if ($configuredDomain === null) {
            return;
        }

        $observedHosts = $this->extractObservedHosts($request);

        if ($observedHosts === []) {
            throw ValidationException::withMessages([
                'write_key' => 'Signals ingestion requires a request origin or URL that matches the tracked property domain.',
            ]);
        }

        foreach ($observedHosts as $observedHost) {
            if ($this->hostMatchesDomain($observedHost, $configuredDomain)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'write_key' => 'Signals ingestion origin does not match the tracked property domain.',
        ]);
    }

    /** @return list<string> */
    private function extractObservedHosts(Request $request): array
    {
        $hosts = [];

        foreach ([$request->input('url'), $request->headers->get('Origin'), $request->headers->get('Referer')] as $value) {
            $host = $this->normalizeHost($value);

            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function normalizeHost(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $host = $value;

        if (str_contains($value, '://')) {
            $parsedHost = parse_url($value, PHP_URL_HOST);

            if (! is_string($parsedHost) || $parsedHost === '') {
                return null;
            }

            $host = $parsedHost;
        }

        return mb_strtolower(mb_trim($host, ". \t\n\r\0\x0B"));
    }

    private function hostMatchesDomain(string $observedHost, string $configuredDomain): bool
    {
        return $observedHost === $configuredDomain
            || str_ends_with($observedHost, '.' . $configuredDomain);
    }
}
