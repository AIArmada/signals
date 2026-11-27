<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Reporting\Api\GoalAdministration;
use AIArmada\Signals\Features\Reporting\Models\SignalGoal;
use Illuminate\Support\Arr;

final readonly class AdministerGoal implements GoalAdministration
{
    public function create(array $data): string
    {
        return (new SignalGoal)->getConnection()->transaction(function () use ($data): string {
            $record = new SignalGoal($this->attributes($data));

            if (SignalGoal::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolveConfigured(SignalGoal::ownerScopeConfig());
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
        (new SignalGoal)->getConnection()->transaction(function () use ($id, $data): void {
            $this->lockRecord($id)->update($this->attributes($data));
        }, attempts: 3);
    }

    public function delete(string $id): bool
    {
        return (new SignalGoal)->getConnection()->transaction(
            fn (): bool => $this->lockRecord($id)->delete() === true,
            attempts: 3,
        );
    }

    private function lockRecord(string $id): SignalGoal
    {
        $query = SignalGoal::query();

        if (SignalGoal::ownerScopeConfig()->enabled) {
            $owner = OwnerContext::resolveConfigured(SignalGoal::ownerScopeConfig());
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
            'tracked_property_id', 'name', 'slug', 'description',
            'goal_type', 'event_name', 'event_category', 'conditions',
            'is_active',
        ]);
    }
}
