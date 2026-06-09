<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\SignalUserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

final class ResolveSession
{
    public function __construct(
        private readonly SignalUserAgentParser $userAgentParser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, ?SignalIdentity $identity, array $payload): ?SignalSession
    {
        $sessionIdentifier = $payload['session_identifier'] ?? null;

        if (! is_string($sessionIdentifier) || $sessionIdentifier === '') {
            return null;
        }

        $session = SignalSession::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('session_identifier', $sessionIdentifier)
            ->first();

        $startedAt = isset($payload['session_started_at']) && is_string($payload['session_started_at'])
            ? CarbonImmutable::parse($payload['session_started_at'])
            : $this->resolveOccurredAt($payload);

        if (! $session instanceof SignalSession) {
            $session = new SignalSession([
                'tracked_property_id' => $trackedProperty->id,
                'session_identifier' => $sessionIdentifier,
                'started_at' => $startedAt,
            ]);
        }

        $rawUserAgent = null;
        /** @var array{device_type: string|null, device_brand: string|null, device_model: string|null, browser: string|null, browser_version: string|null, os: string|null, os_version: string|null, is_bot: bool} $parsed */
        $parsed = ['device_type' => null, 'device_brand' => null, 'device_model' => null, 'browser' => null, 'browser_version' => null, 'os' => null, 'os_version' => null, 'is_bot' => false];
        $capturedIp = null;
        $request = null;

        if (! app()->runningInConsole() && app()->bound('request')) {
            $request = request();
            $rawUserAgent = $request->userAgent() ?? '';
            $parsed = $this->userAgentParser->parse($rawUserAgent);

            if (config('signals.features.ip_tracking.enabled', true)) {
                $ip = $this->resolveClientIp($request);
                if ($ip !== null) {
                    $capturedIp = config('signals.features.ip_tracking.anonymize', false)
                        ? $this->anonymizeIpAddress($ip)
                        : $ip;
                }
            }
        }

        $storeRaw = (bool) config('signals.features.ua_parsing.store_raw', true);

        $session->fill([
            'signal_identity_id' => $identity?->id,
            'entry_path' => $session->entry_path ?? ($payload['path'] ?? null),
            'exit_path' => $payload['path'] ?? $session->exit_path,
            'country' => $session->country ?? $this->resolveCountry($request, $payload),
            'country_source' => $session->country_source ?? $this->resolveCountrySource($request, $payload),
            'device_type' => $payload['device_type'] ?? ($parsed['device_type'] ?? $session->device_type),
            'device_brand' => $payload['device_brand'] ?? ($parsed['device_brand'] ?? $session->device_brand),
            'device_model' => $payload['device_model'] ?? ($parsed['device_model'] ?? $session->device_model),
            'browser' => $payload['browser'] ?? ($parsed['browser'] ?? $session->browser),
            'browser_version' => $payload['browser_version'] ?? ($parsed['browser_version'] ?? $session->browser_version),
            'os' => $payload['os'] ?? ($parsed['os'] ?? $session->os),
            'os_version' => $payload['os_version'] ?? ($parsed['os_version'] ?? $session->os_version),
            'is_bot' => $parsed['is_bot'],
            'user_agent' => $session->user_agent ?? ($rawUserAgent !== null && $storeRaw ? $rawUserAgent : null),
            'ip_address' => $session->ip_address ?? $capturedIp,
            'referrer' => $session->referrer ?? ($payload['referrer'] ?? null),
            'utm_source' => $payload['utm_source'] ?? $session->utm_source,
            'utm_medium' => $payload['utm_medium'] ?? $session->utm_medium,
            'utm_campaign' => $payload['utm_campaign'] ?? $session->utm_campaign,
            'utm_content' => $payload['utm_content'] ?? $session->utm_content,
            'utm_term' => $payload['utm_term'] ?? $session->utm_term,
        ]);

        if (! $session->exists) {
            $session->is_bounce = true;
        }

        $this->syncOwnerFromProperty($session, $trackedProperty);

        try {
            $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);
            OwnerContext::withOwner($owner, static fn (): bool => $session->save());

            return $session;
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }

        $session = SignalSession::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id)
            ->where('session_identifier', $sessionIdentifier)
            ->firstOrFail();

        $session->fill([
            'signal_identity_id' => $identity?->id,
            'entry_path' => $session->entry_path ?? ($payload['path'] ?? null),
            'exit_path' => $payload['path'] ?? $session->exit_path,
            'country' => $session->country ?? $this->resolveCountry($request, $payload),
            'country_source' => $session->country_source ?? $this->resolveCountrySource($request, $payload),
            'device_type' => $payload['device_type'] ?? ($parsed['device_type'] ?? $session->device_type),
            'device_brand' => $payload['device_brand'] ?? ($parsed['device_brand'] ?? $session->device_brand),
            'device_model' => $payload['device_model'] ?? ($parsed['device_model'] ?? $session->device_model),
            'browser' => $payload['browser'] ?? ($parsed['browser'] ?? $session->browser),
            'browser_version' => $payload['browser_version'] ?? ($parsed['browser_version'] ?? $session->browser_version),
            'os' => $payload['os'] ?? ($parsed['os'] ?? $session->os),
            'os_version' => $payload['os_version'] ?? ($parsed['os_version'] ?? $session->os_version),
            'is_bot' => $parsed['is_bot'],
            'user_agent' => $session->user_agent ?? ($rawUserAgent !== null && $storeRaw ? $rawUserAgent : null),
            'ip_address' => $session->ip_address ?? $capturedIp,
            'referrer' => $session->referrer ?? ($payload['referrer'] ?? null),
            'utm_source' => $payload['utm_source'] ?? $session->utm_source,
            'utm_medium' => $payload['utm_medium'] ?? $session->utm_medium,
            'utm_campaign' => $payload['utm_campaign'] ?? $session->utm_campaign,
            'utm_content' => $payload['utm_content'] ?? $session->utm_content,
            'utm_term' => $payload['utm_term'] ?? $session->utm_term,
        ]);

        $this->syncOwnerFromProperty($session, $trackedProperty);
        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);
        OwnerContext::withOwner($owner, static fn (): bool => $session->save());

        return $session;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOccurredAt(array $payload): CarbonImmutable
    {
        $occurredAt = $payload['occurred_at'] ?? null;

        return is_string($occurredAt) ? CarbonImmutable::parse($occurredAt) : CarbonImmutable::now();
    }

    private function syncOwnerFromProperty(object $model, TrackedProperty $trackedProperty): void
    {
        $model->owner_type = $trackedProperty->owner_type;
        $model->owner_id = $trackedProperty->owner_id;
    }

    private function resolveCountry(?Request $request, array $payload): ?string
    {
        if ($request !== null) {
            $cfCountry = $request->header('CF-IPCountry');
            if (
                $cfCountry !== null
                && preg_match('/^[A-Z]{2}$/', $cfCountry)
                && ! in_array($cfCountry, ['XX', 'T1'], true)
            ) {
                return $cfCountry;
            }
        }

        $payloadCountry = $payload['country'] ?? null;
        if (is_string($payloadCountry) && preg_match('/^[A-Z]{2}$/', mb_strtoupper($payloadCountry))) {
            return mb_strtoupper($payloadCountry);
        }

        return null;
    }

    private function resolveCountrySource(?Request $request, array $payload): ?string
    {
        if ($request !== null) {
            $cfCountry = $request->header('CF-IPCountry');
            if (
                $cfCountry !== null
                && preg_match('/^[A-Z]{2}$/', $cfCountry)
                && ! in_array($cfCountry, ['XX', 'T1'], true)
            ) {
                return 'cloudflare';
            }
        }

        $payloadCountry = $payload['country'] ?? null;
        if (is_string($payloadCountry) && $payloadCountry !== '') {
            return 'payload';
        }

        return null;
    }

    private function resolveClientIp(Request $request): ?string
    {
        $cfIp = $request->header('CF-Connecting-IP');
        if ($cfIp !== null && filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
            return $cfIp;
        }

        return $request->ip();
    }

    private function anonymizeIpAddress(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return $ip;
            }
            $anonymized = mb_substr($packed, 0, 6) . str_repeat("\x00", 10);

            return inet_ntop($anonymized) ?: $ip;
        }

        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';

            return implode('.', $parts);
        }

        return $ip;
    }
}
