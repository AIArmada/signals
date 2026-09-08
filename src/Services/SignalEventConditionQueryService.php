<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use Illuminate\Database\Eloquent\Builder;

final class SignalEventConditionQueryService
{
    /**
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public function apply(Builder $query, ?array $conditions, string $matchType = 'all'): Builder
    {
        return SignalCondition::applyToQuery($query, $conditions, $matchType);
    }

    public function failClosed(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }
}
