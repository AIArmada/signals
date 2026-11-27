<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Exceptions\CommerceSignalRecordingFailed;
use AIArmada\Signals\Features\Alerts\Api\AlertEventEvaluation;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\SignalIdentity;
use AIArmada\Signals\Features\Ingestion\Models\SignalSession;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use AIArmada\Signals\Features\Ingestion\Queries\CrossTenantQuery;
use AIArmada\Signals\Features\Ingestion\Support\SignalEventPropertyTypeInferrer;
use AIArmada\Signals\Features\Ingestion\Support\SignalPropertyFilter;
use AIArmada\Signals\Support\DuplicateKeyViolation;
use AIArmada\Signals\Support\RecordingTransaction;
use AIArmada\Signals\Support\SignalCurrency;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class IngestSignalEvent
{
    public function __construct(
        private SignalEventPropertyTypeInferrer $propertyTypeInferrer,
        private ResolveSession $resolveSession,
        private SignalPropertyFilter $propertyFilter,
        private AlertEventEvaluation $alertEvaluation,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, array $payload, bool $trusted): SignalEvent
    {
        $ingestionSource = $trusted
            ? SignalEvent::INGESTION_SOURCE_TRUSTED
            : SignalEvent::INGESTION_SOURCE_BROWSER;
        $sourceEventId = $trusted ? $this->stringValue($payload['source_event_id'] ?? null) : null;
        $idempotencyKey = $this->stringValue($payload['idempotency_key'] ?? null)
            ?? ($trusted ? $sourceEventId : null);

        $revenueMinor = $trusted ? $this->normalizeRevenueMinor($payload['revenue_minor'] ?? 0) : 0;
        $propertyCurrency = SignalCurrency::code($trackedProperty->currency);
        $payloadCurrency = $trusted ? SignalCurrency::normalize($payload['currency'] ?? null) : null;

        if ($payloadCurrency !== null && $payloadCurrency !== $propertyCurrency && $revenueMinor > 0) {
            throw new InvalidArgumentException(sprintf(
                'Cannot record [%s] revenue on a tracked property reporting in [%s].',
                $payloadCurrency,
                $propertyCurrency,
            ));
        }

        $currency = $propertyCurrency;

        if ($idempotencyKey !== null) {
            $existing = CrossTenantQuery::findExistingEvent($trackedProperty, $ingestionSource, $idempotencyKey);

            if ($existing instanceof SignalEvent) {
                return $existing;
            }
        }

        $occurredAt = $this->resolveOccurredAt($payload);
        $rawProperties = is_array($payload['properties'] ?? null) ? $payload['properties'] : null;
        $properties = $trusted ? $rawProperties : $this->filterProperties($rawProperties);
        $propertyTypes = $this->propertyTypeInferrer->infer($properties);

        try {
            $event = $this->withTrackedPropertyOwner($trackedProperty, fn (): SignalEvent => RecordingTransaction::run(function () use (
                $trackedProperty,
                $payload,
                $ingestionSource,
                $sourceEventId,
                $idempotencyKey,
                $occurredAt,
                $properties,
                $propertyTypes,
                $revenueMinor,
                $currency
            ): SignalEvent {
                $identity = $this->resolveIdentity($trackedProperty, $payload, $occurredAt);
                $session = $this->resolveSession->handle($trackedProperty, $identity, $payload);

                $event = new SignalEvent([
                    'tracked_property_id' => $trackedProperty->id,
                    'signal_session_id' => $session?->id,
                    'signal_identity_id' => $identity?->id,
                    'occurred_at' => $occurredAt,
                    'event_name' => (string) $payload['event_name'],
                    'event_category' => (string) ($payload['event_category'] ?? 'custom'),
                    'ingestion_source' => $ingestionSource,
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
                    'revenue_minor' => $revenueMinor,
                    'currency' => $currency,
                    'properties' => $properties,
                    'property_types' => $propertyTypes,
                ]);

                $this->syncOwnerFromProperty($event, $trackedProperty);
                $event->save();

                if ($session instanceof SignalSession) {
                    $fresh = SignalSession::query()->lockForUpdate()->find($session->id);

                    if ($fresh instanceof SignalSession) {
                        if ($fresh->ended_at === null || $occurredAt->greaterThanOrEqualTo($fresh->ended_at)) {
                            $fresh->exit_path = $payload['path'] ?? $fresh->exit_path;
                            $fresh->ended_at = $occurredAt;
                            $fresh->duration_milliseconds = max(0, (int) ($fresh->started_at?->diffInMilliseconds($occurredAt) ?? 0));
                        }

                        // Shared-locking read: after waiting on the session row lock, a
                        // plain read could still see the pre-commit snapshot and
                        // wrongly keep bounced_at on a multi-event session.
                        $hasOtherEvents = $fresh->events()->whereKeyNot($event->id)->sharedLock()->value('id') !== null;
                        $fresh->bounced_at = $hasOtherEvents ? null : $fresh->bounced_at ?? CarbonImmutable::now();
                        $fresh->save();
                    }
                }

                return $event;
                // Bounded top-level retries: simultaneous duplicate inserts
                // can deadlock on InnoDB; the retry then hits the winner path.
            }, 5));
        } catch (QueryException $e) {
            $existing = $idempotencyKey !== null && DuplicateKeyViolation::is($e)
                ? CrossTenantQuery::findExistingEvent($trackedProperty, $ingestionSource, $idempotencyKey)
                : null;

            if (! $existing instanceof SignalEvent) {
                throw $e;
            }

            return $existing;
        }

        DB::afterCommit(function () use ($event, $trackedProperty): void {
            try {
                $this->evaluateAlertsAfterIngest($event);
            } catch (Throwable $e) {
                // The event is already persisted; a failing optional alert
                // evaluation must not fail ingestion or the outer commit.
                CommerceSignalRecordingFailed::reportSafely(
                    CommerceSignalRecordingFailed::forAlertEvaluation(
                        (string) $event->getKey(),
                        (string) $trackedProperty->getKey(),
                        $event->getAttributes()['owner_type'] ?? null,
                        $event->getAttributes()['owner_id'] ?? null,
                        $e,
                    )
                );
            }
        });

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveIdentity(TrackedProperty $trackedProperty, array $payload, CarbonImmutable $observedAt): ?SignalIdentity
    {
        if (($payload['external_id'] ?? null) === null && ($payload['anonymous_id'] ?? null) === null) {
            return null;
        }

        return app(IdentifySignalIdentity::class)->handle($trackedProperty, $payload, $observedAt);
    }

    private function resolveOccurredAt(array $payload): CarbonImmutable
    {
        $occurredAt = $payload['occurred_at'] ?? null;

        if (! is_string($occurredAt) || mb_trim($occurredAt) === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse($occurredAt);
        } catch (InvalidFormatException) {
            return CarbonImmutable::now();
        }
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
        $this->alertEvaluation->afterIngest((string) $event->getKey(), (string) $event->tracked_property_id, $event->owner_type, $event->owner_id);
    }

    /**
     * @param  array<string, mixed>|null  $properties
     * @return array<string, mixed>|null
     */
    private function filterProperties(?array $properties): ?array
    {
        return $this->propertyFilter->filter($properties);
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function normalizeRevenueMinor(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Revenue must be a base-10 integer minor-unit amount.');
        }

        $normalized = mb_trim($value);

        if ($normalized === '' || preg_match('/\A[+-]?\d+\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Revenue must be a base-10 integer minor-unit amount.');
        }

        $negative = str_starts_with($normalized, '-');
        $digits = mb_ltrim(mb_ltrim($normalized, '+-'), '0');

        if ($digits === '' || $negative) {
            return 0;
        }

        $max = (string) PHP_INT_MAX;

        if (mb_strlen($digits) > mb_strlen($max) || (mb_strlen($digits) === mb_strlen($max) && strcmp($digits, $max) > 0)) {
            throw new InvalidArgumentException('Revenue exceeds the supported minor-unit range.');
        }

        return (int) $digits;
    }
}
