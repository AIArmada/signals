<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\SignalEventPropertyTypeInferrer;
use AIArmada\Signals\Services\SignalsIngestionRequestValidator;
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
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, array $payload): SignalEvent
    {
        $identity = $this->resolveIdentity($trackedProperty, $payload);
        $session = $this->resolveSession($trackedProperty, $identity, $payload);
        $occurredAt = $this->resolveOccurredAt($payload);
        $properties = is_array($payload['properties'] ?? null) ? $payload['properties'] : null;

        $event = new SignalEvent([
            'tracked_property_id' => $trackedProperty->id,
            'signal_session_id' => $session?->id,
            'signal_identity_id' => $identity?->id,
            'occurred_at' => $occurredAt,
            'event_name' => (string) $payload['event_name'],
            'event_category' => (string) ($payload['event_category'] ?? 'custom'),
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
        $event->save();

        if ($session instanceof SignalSession) {
            $session->exit_path = $payload['path'] ?? $session->exit_path;
            $session->ended_at = $occurredAt;
            $session->duration_seconds = max(0, $session->started_at?->diffInSeconds($occurredAt) ?? 0);
            $session->is_bounce = ! $session->events()->whereKeyNot($event->id)->exists();
            $session->save();
        }

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
            'properties' => ['nullable', 'array'],
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

        $session->fill([
            'signal_identity_id' => $identity?->id,
            'entry_path' => $session->entry_path ?? ($payload['path'] ?? null),
            'exit_path' => $payload['path'] ?? $session->exit_path,
            'country' => $payload['country'] ?? $session->country,
            'device_type' => $payload['device_type'] ?? $session->device_type,
            'browser' => $payload['browser'] ?? $session->browser,
            'os' => $payload['os'] ?? $session->os,
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
        $session->save();

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
        if (! $trackedProperty->hasOwner()) {
            return;
        }

        $model->owner_type = $trackedProperty->owner_type;
        $model->owner_id = $trackedProperty->owner_id;
    }
}
