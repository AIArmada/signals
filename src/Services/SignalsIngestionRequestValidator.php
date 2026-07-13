<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SignalsIngestionRequestValidator
{
    /** @var list<string> */
    private const BROWSER_FORBIDDEN_FIELDS = [
        'checkout_session_id',
        'conversion_id',
        'currency',
        'external_reference',
        'order_id',
        'order_number',
        'order_reference',
        'payment_id',
        'revenue',
        'revenue_minor',
        'source_event_id',
        'source_transaction_id',
        'transaction_id',
    ];

    public function __construct(private readonly RateLimiter $rateLimiter) {}

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
        $this->assertPayloadWithinLimits($request, 'browser');
        $this->assertBrowserEventAllowed($eventName);
        $this->assertBrowserPayloadContainsNoTrustedFields($request->all());
        $this->assertRateLimit(
            key: 'signals:browser:' . hash('sha256', $writeKey) . ':' . ($request->ip() ?? 'unknown'),
            attempts: max(1, (int) config('signals.ingestion.browser.rate_limit_per_minute', 120)),
        );
    }

    public function assertTrustedPayloadWithinLimits(Request $request): void
    {
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

    private function assertPayloadWithinLimits(Request $request, string $boundary): void
    {
        $maxBytes = max(1024, (int) config("signals.ingestion.{$boundary}.max_bytes", 32768));

        if (strlen($request->getContent()) > $maxBytes) {
            throw ValidationException::withMessages([
                'payload' => "The {$boundary} Signals payload exceeds {$maxBytes} bytes.",
            ]);
        }

        $maxDepth = max(1, (int) config("signals.ingestion.{$boundary}.max_depth", 4));
        $maxKeys = max(1, (int) config("signals.ingestion.{$boundary}.max_keys", 64));
        $maxStringBytes = max(1, (int) config("signals.ingestion.{$boundary}.max_string_bytes", 1024));
        $keyCount = 0;

        $this->walkPayload($request->all(), 1, $maxDepth, $maxKeys, $maxStringBytes, $keyCount);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function walkPayload(
        array $values,
        int $depth,
        int $maxDepth,
        int $maxKeys,
        int $maxStringBytes,
        int &$keyCount,
    ): void {
        if ($depth > $maxDepth) {
            throw ValidationException::withMessages([
                'payload' => "The Signals payload exceeds the maximum nesting depth of {$maxDepth}.",
            ]);
        }

        foreach ($values as $key => $value) {
            ++$keyCount;

            if ($keyCount > $maxKeys) {
                throw ValidationException::withMessages([
                    'payload' => "The Signals payload exceeds the maximum key count of {$maxKeys}.",
                ]);
            }

            if (is_string($key) && strlen($key) > $maxStringBytes) {
                throw ValidationException::withMessages([
                    'payload' => "A Signals payload key exceeds {$maxStringBytes} bytes.",
                ]);
            }

            if (is_string($value) && strlen($value) > $maxStringBytes) {
                throw ValidationException::withMessages([
                    'payload' => "A Signals payload value exceeds {$maxStringBytes} bytes.",
                ]);
            }

            if (is_array($value)) {
                $this->walkPayload($value, $depth + 1, $maxDepth, $maxKeys, $maxStringBytes, $keyCount);
            }
        }
    }

    private function assertBrowserEventAllowed(string $eventName): void
    {
        $configured = config('signals.ingestion.browser.event_allowlist', []);
        $patterns = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $eventName)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'event_name' => 'This event is not allowed on the public browser ingestion route.',
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function assertBrowserPayloadContainsNoTrustedFields(array $payload): void
    {
        $found = $this->findForbiddenField($payload);

        if ($found === null) {
            return;
        }

        throw ValidationException::withMessages([
            $found => 'Financial and transaction identifiers require the signed server-outcome route.',
        ]);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function findForbiddenField(array $values): ?string
    {
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key) ?? $key);

                if (in_array($normalized, self::BROWSER_FORBIDDEN_FIELDS, true)) {
                    return $key;
                }
            }

            if (is_array($value)) {
                $found = $this->findForbiddenField($value);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
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

        return strtolower(trim($host, ". \t\n\r\0\x0B"));
    }

    private function hostMatchesDomain(string $observedHost, string $configuredDomain): bool
    {
        return $observedHost === $configuredDomain
            || str_ends_with($observedHost, '.' . $configuredDomain);
    }
}
