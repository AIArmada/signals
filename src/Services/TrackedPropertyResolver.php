<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Model;

final class TrackedPropertyResolver
{
    public function resolveForModel(Model $model, ?string $propertyType = null): ?TrackedProperty
    {
        return $this->resolveForOwner($this->resolveOwner($model), $propertyType);
    }

    public function resolveForOwner(?Model $owner, ?string $propertyType = null, ?string $integration = null): ?TrackedProperty
    {
        $configured = $this->resolveConfiguredProperty($owner, $propertyType, $integration);

        if ($configured instanceof TrackedProperty) {
            return $configured;
        }

        $query = TrackedProperty::query()
            ->withoutOwnerScope()
            ->where('is_active', true)
            ->where('type', $propertyType ?? (string) config('signals.defaults.property_type', 'website'));

        if ($owner instanceof Model) {
            $query
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', (string) $owner->getKey());
        } else {
            $query
                ->whereNull('owner_type')
                ->whereNull('owner_id');
        }

        $properties = $query
            ->orderBy('created_at')
            ->limit(2)
            ->get();

        if ($properties->count() !== 1) {
            if ($properties->isEmpty() && $this->shouldAutoCreate($integration)) {
                return $this->createDefaultProperty($owner, $propertyType, (string) $integration);
            }

            return null;
        }

        return $properties->first();
    }

    public function resolveForOwnerReference(?string $ownerType, string | int | null $ownerId, ?string $propertyType = null, ?string $integration = null): ?TrackedProperty
    {
        $owner = OwnerContext::fromTypeAndId($ownerType, $ownerId);

        return $this->resolveForOwner($owner, $propertyType, $integration);
    }

    private function resolveConfiguredProperty(?Model $owner, ?string $propertyType, ?string $integration): ?TrackedProperty
    {
        if ($integration === null || $integration === '') {
            return null;
        }

        $propertyId = config("signals.integrations.{$integration}.tracked_property.id");

        if (! is_string($propertyId) || $propertyId === '') {
            return null;
        }

        $query = TrackedProperty::query()
            ->withoutOwnerScope()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->where('type', $propertyType ?? (string) config('signals.defaults.property_type', 'website'));

        if ($owner instanceof Model) {
            $query->where('owner_type', $owner->getMorphClass())->where('owner_id', (string) $owner->getKey());
        } else {
            $query->whereNull('owner_type')->whereNull('owner_id');
        }

        $property = $query->first();

        return $property instanceof TrackedProperty ? $property : null;
    }

    private function shouldAutoCreate(?string $integration): bool
    {
        if ($integration === null || $integration === '') {
            return false;
        }

        return (bool) config("signals.integrations.{$integration}.enabled", false)
            && (bool) config("signals.integrations.{$integration}.tracked_property.auto_create", false);
    }

    private function createDefaultProperty(?Model $owner, ?string $propertyType, string $integration): TrackedProperty
    {
        $slug = (string) config("signals.integrations.{$integration}.tracked_property.slug", 'commerce');
        $name = (string) config("signals.integrations.{$integration}.tracked_property.name", 'Commerce');
        $type = $propertyType ?? (string) config('signals.defaults.property_type', 'website');

        return OwnerContext::withOwner($owner, fn (): TrackedProperty => TrackedProperty::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'timezone' => (string) config('signals.defaults.timezone', 'UTC'),
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'is_active' => true,
            'settings' => [
                'integration' => $integration,
                'auto_created' => true,
            ],
        ]));
    }

    private function resolveOwner(Model $model): ?Model
    {
        if (! method_exists($model, 'owner')) {
            return null;
        }

        $owner = $model->getRelationValue('owner');

        return $owner instanceof Model ? $owner : null;
    }
}
