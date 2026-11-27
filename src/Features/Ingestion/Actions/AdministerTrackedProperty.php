<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Ingestion\Api\PropertyAdministration;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use Illuminate\Support\Arr;

final readonly class AdministerTrackedProperty implements PropertyAdministration
{
    public function create(array $data): string
    {
        return (new TrackedProperty)->getConnection()->transaction(function () use ($data): string {
            $record = new TrackedProperty($this->attributes($data));

            if (TrackedProperty::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolveConfigured(TrackedProperty::ownerScopeConfig());
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Signals administration requires an owner context.');

                if ($owner !== null) {
                    $record->owner()->associate($owner);
                }
            }

            $record->save();

            return (string) $record->getKey();
        }, attempts: 3);
    }

    public function update(string $id, array $data): void
    {
        (new TrackedProperty)->getConnection()->transaction(function () use ($id, $data): void {
            $this->lockRecord($id)->update($this->attributes($data));
        }, attempts: 3);
    }

    public function delete(string $id): bool
    {
        return (new TrackedProperty)->getConnection()->transaction(
            fn (): bool => $this->lockRecord($id)->delete() === true,
            attempts: 3,
        );
    }

    private function lockRecord(string $id): TrackedProperty
    {
        $query = TrackedProperty::query();

        if (TrackedProperty::ownerScopeConfig()->enabled) {
            $owner = OwnerContext::resolveConfigured(TrackedProperty::ownerScopeConfig());
            OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Signals administration requires an owner context.');

            $query = $owner === null
                ? $query->globalOnly()
                : $query->forOwner($owner, includeGlobal: false);
        }

        return $query->lockForUpdate()->findOrFail($id);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return Arr::only($data, [
            'name', 'slug', 'write_key', 'domain',
            'type', 'timezone', 'currency', 'is_active',
            'settings',
        ]);
    }
}
