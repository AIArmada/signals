<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SignalsIngestionRequestValidator
{
    public function resolveTrackedProperty(Request $request, string $writeKey): TrackedProperty
    {
        $trackedProperty = TrackedProperty::query()
            ->withoutOwnerScope()
            ->where('write_key', $writeKey)
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertRequestMatchesTrackedPropertyDomain($request, $trackedProperty);

        return $trackedProperty;
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

    /**
     * @return list<string>
     */
    private function extractObservedHosts(Request $request): array
    {
        $hosts = [];

        foreach ([$request->input('url'), $request->headers->get('Origin'), $request->headers->get('Referer')] as $value) {
            $host = $this->normalizeHost($value);

            if ($host === null) {
                continue;
            }

            $hosts[] = $host;
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
