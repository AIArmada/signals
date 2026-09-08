<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Actions\IngestSignalEvent;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\TrackedPropertyResolver;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Shared boundary operations for trusted commerce sources.
 *
 * Source recorders own the field mapping. This class only provides the
 * explicit conversions and common persistence/enrichment boundary they share.
 */
final class SignalRecorderSupport
{
    public function __construct(
        private readonly TrackedPropertyResolver $trackedPropertyResolver,
        private readonly IngestSignalEvent $ingestSignalEvent,
    ) {}

    public function ingest(TrackedProperty $trackedProperty, array $payload): SignalEvent
    {
        return $this->ingestSignalEvent->handle($trackedProperty, $payload, trusted: true);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    public function enrichProperties(Model $source, TrackedProperty $trackedProperty, array $properties): ?array
    {
        $baseProperties = array_filter($properties, static fn (mixed $value): bool => $value !== null);

        if (! app()->bound('growth.signal_event_property_enricher')) {
            return $baseProperties === [] ? null : $baseProperties;
        }

        $enricher = app('growth.signal_event_property_enricher');

        if (! is_object($enricher) || ! method_exists($enricher, 'handle')) {
            return $baseProperties === [] ? null : $baseProperties;
        }

        $handleEnrichment = fn (): mixed => $enricher->handle($source, $trackedProperty, $baseProperties);

        $enriched = OwnerContext::hasOverride()
            ? $handleEnrichment()
            : OwnerContext::withOwner($trackedProperty->owner, $handleEnrichment);

        if (! is_array($enriched)) {
            return $baseProperties === [] ? null : $baseProperties;
        }

        return $enriched === [] ? null : $enriched;
    }

    public function resolveTrackedPropertyForCart(object $cart): ?TrackedProperty
    {
        $storage = $this->requiredMethod($cart, 'storage');
        $ownerType = $this->requiredMethod($storage, 'getOwnerType');
        $ownerId = $this->requiredMethod($storage, 'getOwnerId');

        return $this->trackedPropertyResolver->resolveForOwnerReference(
            is_string($ownerType) ? $ownerType : null,
            is_string($ownerId) || is_int($ownerId) ? $ownerId : null,
            null,
            'cart',
        );
    }

    public function resolveTrackedPropertyForModel(Model $model): ?TrackedProperty
    {
        return $this->trackedPropertyResolver->resolveForModel($model);
    }

    public function resolveTrackedPropertyForOwnerReference(
        ?string $ownerType,
        string | int | null $ownerId,
        ?string $writeKey = null,
        ?string $source = null,
    ): ?TrackedProperty {
        return $this->trackedPropertyResolver->resolveForOwnerReference($ownerType, $ownerId, $writeKey, $source);
    }

    public function resolveTrackedPropertyForAffiliateModel(Model $model): ?TrackedProperty
    {
        return $this->trackedPropertyResolver->resolveForOwnerReference(
            $this->stringValue($model->getAttribute('owner_type')),
            $model->getAttribute('owner_id'),
        );
    }

    public function modelAttribute(Model $model, string $attribute): mixed
    {
        if (! array_key_exists($attribute, $model->getAttributes())) {
            return null;
        }

        return $model->getAttribute($attribute);
    }

    public function requiredModelInt(Model $model, string $attribute): int
    {
        $value = $this->modelAttribute($model, $attribute);

        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException(sprintf('Trusted signal source is missing integer attribute [%s].', $attribute));
        }

        return (int) $value;
    }

    /**
     * @param  list<string>  $attributes
     */
    public function requiredModelTimestamp(Model $model, array $attributes): string
    {
        foreach ($attributes as $attribute) {
            $value = $this->timestampValue($this->modelAttribute($model, $attribute));

            if ($value !== null) {
                return $value;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Trusted signal source is missing timestamp attributes [%s].',
            implode(', ', $attributes),
        ));
    }

    public function requiredMethod(object $object, string $method): mixed
    {
        if (! method_exists($object, $method)) {
            throw new InvalidArgumentException(sprintf(
                'Trusted signal source [%s] is missing method [%s].',
                $object::class,
                $method,
            ));
        }

        return $object->{$method}();
    }

    public function requiredStringMethod(object $object, string $method): string
    {
        $value = $this->stringValue($this->requiredMethod($object, $method));

        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('Trusted signal source method [%s] returned no string.', $method));
        }

        return $value;
    }

    public function requiredIntMethod(object $object, string $method): int
    {
        $value = $this->requiredMethod($object, $method);

        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException(sprintf('Trusted signal source method [%s] returned no integer.', $method));
        }

        return (int) $value;
    }

    public function requiredPublicScalar(object $object, string $property): string
    {
        if (! property_exists($object, $property)) {
            throw new InvalidArgumentException(sprintf(
                'Trusted signal source [%s] is missing property [%s].',
                $object::class,
                $property,
            ));
        }

        $value = $this->stringValue($object->{$property});

        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('Trusted signal source property [%s] is empty.', $property));
        }

        return $value;
    }

    public function optionalPublicScalar(object $object, string $property): ?string
    {
        if (! property_exists($object, $property)) {
            return null;
        }

        return $this->stringValue($object->{$property});
    }

    public function requiredPublicInt(object $object, string $property): int
    {
        if (! property_exists($object, $property)) {
            throw new InvalidArgumentException(sprintf(
                'Trusted signal source [%s] is missing property [%s].',
                $object::class,
                $property,
            ));
        }

        $value = $object->{$property};

        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException(sprintf('Trusted signal source property [%s] is not an integer.', $property));
        }

        return (int) $value;
    }

    public function optionalPublicInt(object $object, string $property): ?int
    {
        if (! property_exists($object, $property)) {
            return null;
        }

        $value = $object->{$property};

        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    public function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    public function timestampValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) ? $value : null;
    }

    public function normalizeStateValue(mixed $value): ?string
    {
        if (is_object($value) && method_exists($value, 'getValue')) {
            $resolved = $value->getValue();

            return is_scalar($resolved) ? (string) $resolved : null;
        }

        return $this->stringValue($value);
    }

    public function buildCartSessionIdentifier(string $cartIdentifier, string $instanceName): string
    {
        return 'cart:' . $instanceName . ':' . $cartIdentifier;
    }

    public function buildAffiliateSessionIdentifier(?string $identifier, string $instanceName): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return 'affiliate:' . $instanceName . ':' . $identifier;
    }

    public function isEventRecordingEnabled(string $eventName): bool
    {
        $value = config('signals.recording.events.' . $eventName);

        return $value === null || (bool) $value;
    }

    public function resolveAffiliateModel(
        string $modelClass,
        ?string $identifier,
        ?string $expectedAffiliateId = null,
        ?string $expectedAffiliateCode = null,
        ?string $expectedOwnerType = null,
        string | int | null $expectedOwnerId = null,
    ): ?Model {
        if ($identifier === null || $identifier === '' || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $query = $modelClass::query();

        if (method_exists($modelClass, 'scopeWithoutOwnerScope')) {
            /** @var mixed $ownerScopedQuery */
            $ownerScopedQuery = $query;
            $query = $ownerScopedQuery->withoutOwnerScope();
        }

        if ($expectedAffiliateId !== null && $expectedAffiliateId !== '') {
            $query->where('affiliate_id', $expectedAffiliateId);
        }

        if ($expectedAffiliateCode !== null && $expectedAffiliateCode !== '') {
            $query->where('affiliate_code', $expectedAffiliateCode);
        }

        if ($expectedOwnerType !== null && $expectedOwnerType !== '') {
            $query->where('owner_type', $expectedOwnerType);
        }

        if ($expectedOwnerId !== null && $expectedOwnerId !== '') {
            $query->where('owner_id', $expectedOwnerId);
        }

        $model = $query->find($identifier);

        return $model instanceof Model ? $model : null;
    }
}
