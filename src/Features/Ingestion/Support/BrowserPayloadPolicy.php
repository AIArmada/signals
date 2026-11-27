<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class BrowserPayloadPolicy
{
    /** @var list<string> */
    private const BROWSER_FORBIDDEN_FIELDS = [
        'checkoutsessionid',
        'conversionid',
        'currency',
        'externalreference',
        'orderid',
        'ordernumber',
        'orderreference',
        'paymentid',
        'revenue',
        'revenueminor',
        'sourceeventid',
        'sourcetransactionid',
        'transactionid',
    ];

    /** @param array<array-key, mixed> $payload */
    public function assertEvent(string $eventName, array $payload): void
    {
        $this->assertBrowserEventAllowed($eventName);
        $this->assertNoTrustedFields($payload);
    }

    /** @param array<array-key, mixed> $payload */
    public function assertStructure(array $payload, string $boundary): void
    {
        $maxDepth = max(1, (int) config("signals.ingestion.{$boundary}.max_depth", 4));
        $maxKeys = max(1, (int) config("signals.ingestion.{$boundary}.max_keys", 64));
        $maxStringBytes = max(1, (int) config("signals.ingestion.{$boundary}.max_string_bytes", 1024));
        $keyCount = 0;

        $this->walkPayload($payload, 1, $maxDepth, $maxKeys, $maxStringBytes, $keyCount);
    }

    /**
     * @param  array<array-key, mixed>  $values
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
            $keyCount++;

            if ($keyCount > $maxKeys) {
                throw ValidationException::withMessages([
                    'payload' => "The Signals payload exceeds the maximum key count of {$maxKeys}.",
                ]);
            }

            if (is_string($key) && mb_strlen($key, '8bit') > $maxStringBytes) {
                throw ValidationException::withMessages([
                    'payload' => "A Signals payload key exceeds {$maxStringBytes} bytes.",
                ]);
            }

            if (is_string($value) && mb_strlen($value, '8bit') > $maxStringBytes) {
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
     * @param  array<array-key, mixed>  $payload
     */
    public function assertNoTrustedFields(array $payload): void
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
     * @param  array<array-key, mixed>  $values
     */
    private function findForbiddenField(array $values): ?string
    {
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $key) ?? $key);

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
}
