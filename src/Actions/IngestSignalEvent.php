<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Jobs\EvaluateSignalAlertsForEvent;
use AIArmada\Signals\Models\SignalAlertRule;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use AIArmada\Signals\Services\SignalAlertEvaluator;
use AIArmada\Signals\Services\SignalEventPropertyTypeInferrer;
use AIArmada\Signals\Services\SignalsIngestionRequestValidator;
use AIArmada\Signals\Services\SignalUserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final class IngestSignalEvent
{
    use AsAction;

    public function __construct(
        private readonly SignalEventPropertyTypeInferrer $propertyTypeInferrer,
        private readonly SignalsIngestionRequestValidator $requestValidator,
        private readonly SignalUserAgentParser $userAgentParser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, array $payload): SignalEvent
    {
        $identity = $this->resolveIdentity($trackedProperty, $payload);
        $session = $this->resolveSession($trackedProperty, $identity, $payload);
        $occurredAt = $this->resolveOccurredAt($payload);
        $properties = $this->filterProperties(is_array($payload['properties'] ?? null) ? $payload['properties'] : null);
        $sourceEventId = $this->stringValue($payload['source_event_id'] ?? null);
        $idempotencyKey = $this->stringValue($payload['idempotency_key'] ?? null) ?? $sourceEventId;

        if ($idempotencyKey !== null) {
            $existing = SignalEvent::query()
                ->withoutOwnerScope()
                ->where('tracked_property_id', $trackedProperty->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof SignalEvent) {
                return $existing;
            }
        }

        $event = new SignalEvent([
            'tracked_property_id' => $trackedProperty->id,
            'signal_session_id' => $session?->id,
            'signal_identity_id' => $identity?->id,
            'occurred_at' => $occurredAt,
            'event_name' => (string) $payload['event_name'],
            'event_category' => (string) ($payload['event_category'] ?? 'custom'),
            'idempotency_key' => $idempotencyKey,
            'source_event_id' => $sourceEventId,
            'path' => $payload['path'] ?? null,
            'url' => $payload['url'] ?? null,
            'referrer' => $payload['referrer'] ?? $session?->referrer,
            'source' => $payload['source'] ?? ($payload['utm_source'] ?? $session?->utm_source),
            'medium' => $payload['medium'] ?? ($payload['utm_medium'] ?? $session?->utm_medium),
            'campaign' => $payload['campaign'] ?? ($payload['utm_campaign'] ?? $session?->utm_campaign),
            'content' => $payload['content'] ?? ($payload['utm_content'] ?? $session?->utm_content),
            'term' => $payload['term'] ?? ($payload['utm_term'] ?? $session?->utm_term),
            'revenue_minor' => (int) ($payload['revenue_minor'] ?? 0),
            'currency' => (string) ($payload['currency'] ?? $trackedProperty->currency),
            'properties' => $properties,
            'property_types' => $this->propertyTypeInferrer->infer($properties),
        ]);

        $this->syncOwnerFromProperty($event, $trackedProperty);
        $this->withTrackedPropertyOwner($trackedProperty, static fn (): bool => $event->save());

        if ($session instanceof SignalSession) {
            $session->exit_path = $payload['path'] ?? $session->exit_path;
            $session->ended_at = $occurredAt;
            $durationMilliseconds = max(0, (int) ($session->started_at?->diffInMilliseconds($occurredAt) ?? 0));
            $session->duration_milliseconds = $durationMilliseconds;
            $session->is_bounce = ! $session->events()->whereKeyNot($event->id)->exists();
            $this->withTrackedPropertyOwner($trackedProperty, static fn (): bool => $session->save());
        }

        $this->evaluateAlertsAfterIngest($event);

        return $event;
    }

    public function asController(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string'],
            'event_name' => ['required', 'string', 'max:255'],
            'event_category' => ['nullable', 'string', 'max:100'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'anonymous_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'traits' => ['nullable', 'array'],
            'session_identifier' => ['nullable', 'string', 'max:255'],
            'session_started_at' => ['nullable', 'date'],
            'occurred_at' => ['nullable', 'date'],
            'path' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string'],
            'referrer' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'max:2'],
            'device_type' => ['nullable', 'string', 'max:50'],
            'browser' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:100'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'medium' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:255'],
            'term' => ['nullable', 'string', 'max:255'],
            'revenue_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'browser_version' => ['nullable', 'string', 'max:50'],
            'os_version' => ['nullable', 'string', 'max:50'],
            'device_brand' => ['nullable', 'string', 'max:100'],
            'device_model' => ['nullable', 'string', 'max:100'],
            'is_bot' => ['nullable', 'boolean'],
            'properties' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'source_event_id' => ['nullable', 'string', 'max:255'],
        ]);

        $trackedProperty = $this->requestValidator->resolveTrackedProperty($request, (string) $payload['write_key']);
        $event = $this->handle($trackedProperty, $payload);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'event_id' => $event->id,
                'tracked_property_id' => $trackedProperty->id,
                'identity_id' => $event->signal_identity_id,
                'session_id' => $event->signal_session_id,
            ],
        ], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveIdentity(TrackedProperty $trackedProperty, array $payload): ?SignalIdentity
    {
        if (($payload['external_id'] ?? null) === null && ($payload['anonymous_id'] ?? null) === null) {
            return null;
        }

        return app(IdentifySignalIdentity::class)->handle($trackedProperty, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSession(TrackedProperty $trackedProperty, ?SignalIdentity $identity, array $payload): ?SignalSession
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
        $this->withTrackedPropertyOwner($trackedProperty, static fn (): bool => $session->save());

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

    private function withTrackedPropertyOwner(TrackedProperty $trackedProperty, callable $callback): mixed
    {
        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        return OwnerContext::withOwner($owner, $callback);
    }

    private function evaluateAlertsAfterIngest(SignalEvent $event): void
    {
        if (! (bool) config('signals.features.alerts.evaluate_on_ingest.enabled', false)) {
            return;
        }

        if ((bool) config('signals.features.alerts.evaluate_on_ingest.queue', true)) {
            EvaluateSignalAlertsForEvent::dispatch(
                signalEventId: (string) $event->getKey(),
                ownerType: $event->owner_type,
                ownerId: $event->owner_id,
                ownerIsGlobal: $event->owner_type === null && $event->owner_id === null,
            );

            return;
        }

        $evaluator = app(SignalAlertEvaluator::class);
        $dispatcher = app(SignalAlertDispatcher::class);
        $owner = OwnerContext::fromTypeAndId($event->owner_type, $event->owner_id);

        OwnerContext::withOwner($owner, function () use ($event, $evaluator, $dispatcher): void {
            SignalAlertRule::query()
                ->where('is_active', true)
                ->where(function ($query) use ($event): void {
                    $query->whereNull('tracked_property_id')
                        ->orWhere('tracked_property_id', $event->tracked_property_id);
                })
                ->orderByDesc('priority')
                ->each(function (SignalAlertRule $rule) use ($evaluator, $dispatcher): void {
                    if ($rule->isInCooldown()) {
                        return;
                    }

                    $result = $evaluator->evaluate($rule);

                    if (! $result['matched']) {
                        return;
                    }

                    $dispatcher->dispatch($rule, $result['metric_value'], $result['context']);
                });
        });
    }

    /**
     * @param  array<string, mixed>|null  $properties
     * @return array<string, mixed>|null
     */
    private function filterProperties(?array $properties): ?array
    {
        if ($properties === null) {
            return null;
        }

        $allowlist = config('signals.features.privacy.property_allowlist', []);
        $allowedKeys = is_array($allowlist) ? array_values(array_filter($allowlist, 'is_string')) : [];

        if (in_array('*', $allowedKeys, true)) {
            return $properties;
        }

        $blockedKeys = [
            'email',
            'phone',
            'name',
            'first_name',
            'last_name',
            'customer_email',
            'customer_phone',
            'customer_name',
            'metadata',
            'cart_metadata',
        ];

        return array_filter(
            $properties,
            static fn (mixed $value, string $key): bool => in_array($key, $allowedKeys, true)
                && ! in_array($key, $blockedKeys, true)
                && (is_scalar($value) || is_array($value) || $value === null),
            ARRAY_FILTER_USE_BOTH,
        ) ?: null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
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
            // Zero out last 80 bits (10 bytes) of IPv6 address
            $anonymized = mb_substr($packed, 0, 6) . str_repeat("\x00", 10);

            return inet_ntop($anonymized) ?: $ip;
        }

        // IPv4: zero out last octet
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';

            return implode('.', $parts);
        }

        return $ip;
    }
}
