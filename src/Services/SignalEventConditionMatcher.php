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
        if ($conditions === null || $conditions === []) {
            return true;
        }

        if (! in_array($matchType, ['all', 'any'], true)) {
            return false;
        }

        $results = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                return false;
            }

            $results[] = $this->matchesSingleCondition($event, $condition);
        }

        return $matchType === 'all'
            ? ! in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function matchesSingleCondition(SignalEvent $event, array $condition): bool
    {
        $field = is_string($condition['field'] ?? null) ? $condition['field'] : null;
        $operator = is_string($condition['operator'] ?? null) ? $condition['operator'] : null;
        $value = is_string($condition['value'] ?? null) ? mb_trim($condition['value']) : null;

        if ($field === null || $operator === null || $value === null || $value === '') {
            return false;
        }

        if (! SignalEventConditionDefinition::isSupportedField($field) || ! SignalEventConditionDefinition::isSupportedOperator($operator)) {
            return false;
        }

        $actualValue = $this->resolveFieldValue($event, $field);

        if ($operator === 'in') {
            $values = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));

            return $actualValue !== null && in_array((string) $actualValue, $values, true);
        }

        if (SignalEventConditionDefinition::operatorRequiresNumericComparison($operator)) {
            if (! is_numeric($value) || ! is_numeric($actualValue)) {
                return false;
            }

            $actualNumber = (float) $actualValue;
            $expectedNumber = (float) $value;

            return match ($operator) {
                'greater_than' => $actualNumber > $expectedNumber,
                'greater_than_or_equal' => $actualNumber >= $expectedNumber,
                'less_than' => $actualNumber < $expectedNumber,
                'less_than_or_equal' => $actualNumber <= $expectedNumber,
                default => false,
            };
        }

        $actualText = $actualValue === null ? null : (string) $actualValue;

        return match ($operator) {
            'equals' => $actualText === $value,
            'not_equals' => $actualText !== null && $actualText !== $value,
            'contains' => $actualText !== null && str_contains($actualText, $value),
            'starts_with' => $actualText !== null && str_starts_with($actualText, $value),
            'ends_with' => $actualText !== null && str_ends_with($actualText, $value),
            default => false,
        };
    }

    private function resolveFieldValue(SignalEvent $event, string $field): string | int | float | null
    {
        $propertySegments = SignalEventConditionDefinition::propertySegments($field);

        if ($propertySegments !== null) {
            $value = data_get($event->properties, implode('.', $propertySegments));

            return is_scalar($value) ? $value : null;
        }

        $value = data_get($event, $field);

        return is_scalar($value) ? $value : null;
    }
}
