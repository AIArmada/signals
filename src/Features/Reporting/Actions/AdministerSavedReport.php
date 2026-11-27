<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Reporting\Api\SavedReportAdministration;
use AIArmada\Signals\Features\Reporting\Models\SavedSignalReport;
use Illuminate\Support\Arr;

final readonly class AdministerSavedReport implements SavedReportAdministration
{
    public function create(array $data): string
    {
        return (new SavedSignalReport)->getConnection()->transaction(function () use ($data): string {
            $record = new SavedSignalReport($this->attributes($data));

            if (SavedSignalReport::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolveConfigured(SavedSignalReport::ownerScopeConfig());
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
        (new SavedSignalReport)->getConnection()->transaction(function () use ($id, $data): void {
            $this->lockRecord($id)->update($this->attributes($data));
        }, attempts: 3);
    }

    public function delete(string $id): bool
    {
        return (new SavedSignalReport)->getConnection()->transaction(
            fn (): bool => $this->lockRecord($id)->delete() === true,
            attempts: 3,
        );
    }

    private function lockRecord(string $id): SavedSignalReport
    {
        $query = SavedSignalReport::query();

        if (SavedSignalReport::ownerScopeConfig()->enabled) {
            $owner = OwnerContext::resolveConfigured(SavedSignalReport::ownerScopeConfig());
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
            'tracked_property_id', 'signal_segment_id', 'name', 'slug',
            'report_type', 'description', 'filters', 'settings',
            'is_shared', 'is_active',
        ]);
    }
}
