<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Queries;

use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Support\SignalCondition;

final class SignalEventConditionMatcher
{
    /**
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public function matches(SignalEvent $event, ?array $conditions, string $matchType = 'all'): bool
    {
        return SignalCondition::matches($event->attributesToArray(), $conditions, $matchType);
    }
}
