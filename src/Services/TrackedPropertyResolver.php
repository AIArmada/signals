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

    public function resolveForOwner(?Model $owner, ?string $propertyType = null): ?TrackedProperty
    {
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
            return null;
        }

        return $properties->first();
    }

    public function resolveForOwnerReference(?string $ownerType, string | int | null $ownerId, ?string $propertyType = null): ?TrackedProperty
    {
        $owner = OwnerContext::fromTypeAndId($ownerType, $ownerId);

        return $this->resolveForOwner($owner, $propertyType);
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
