<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Reporting\Api\SegmentAdministration;
use AIArmada\Signals\Features\Reporting\Models\SignalSegment;
use Illuminate\Support\Arr;

final readonly class AdministerSegment implements SegmentAdministration
{
    public function create(array $data): string
    {
        return (new SignalSegment)->getConnection()->transaction(function () use ($data): string {
            $record = new SignalSegment($this->attributes($data));

            if (SignalSegment::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolveConfigured(SignalSegment::ownerScopeConfig());
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
        (new SignalSegment)->getConnection()->transaction(function () use ($id, $data): void {
            $this->lockRecord($id)->update($this->attributes($data));
        }, attempts: 3);
    }

    public function delete(string $id): bool
    {
        return (new SignalSegment)->getConnection()->transaction(
            fn (): bool => $this->lockRecord($id)->delete() === true,
            attempts: 3,
        );
    }

    private function lockRecord(string $id): SignalSegment
    {
        $query = SignalSegment::query();

        if (SignalSegment::ownerScopeConfig()->enabled) {
            $owner = OwnerContext::resolveConfigured(SignalSegment::ownerScopeConfig());
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
            'name', 'slug', 'description', 'match_type',
            'conditions', 'is_active',
        ]);
    }
}
