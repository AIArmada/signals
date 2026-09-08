<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\Signals\Models\SignalEvent;

final class SignalEventConditionMatcher
{
    /**
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public function matches(SignalEvent $event, ?array $conditions, string $matchType = 'all'): bool
    {
        return SignalCondition::matches($event, $conditions, $matchType);
    }
}
