<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Reporting\Models\SavedSignalReport;

final readonly class DetachDeletedSegment
{
    public function handle(string $segmentId): void
    {
        $query = SavedSignalReport::query()->where('signal_segment_id', $segmentId);

        if (SavedSignalReport::ownerScopingEnabled()) {
            $owner = OwnerContext::resolve();
            OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Detaching a deleted segment requires owner context.');
            $query->forOwner($owner, includeGlobal: false);
        }

        $query->update(['signal_segment_id' => null]);
    }
}
