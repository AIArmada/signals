<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

final class SignalEventConditionDefinition
{
    /** @var list<string> */
    public const SUPPORTED_DIRECT_FIELDS = SignalCondition::SUPPORTED_DIRECT_FIELDS;

    /** @var list<string> */
    public const SUPPORTED_OPERATORS = SignalCondition::SUPPORTED_OPERATORS;

    public static function isSupportedField(string $field): bool
    {
        return SignalCondition::isSupportedField($field);
    }

    public static function isSupportedOperator(string $operator): bool
    {
        return SignalCondition::isSupportedOperator($operator);
    }

    public static function operatorRequiresNumericComparison(string $operator): bool
    {
        return SignalCondition::operatorRequiresNumericComparison($operator);
    }

    public static function fieldSupportsNumericComparison(string $field): bool
    {
        return SignalCondition::fieldSupportsNumericComparison($field);
    }

    public static function isPropertyField(string $field): bool
    {
        return SignalCondition::isPropertyField($field);
    }

    /**
     * @return list<string>|null
     */
    public static function propertySegments(string $field): ?array
    {
        return SignalCondition::propertySegments($field);
    }
}
