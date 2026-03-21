<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

final class SignalEventConditionDefinition
{
    /** @var list<string> */
    public const SUPPORTED_DIRECT_FIELDS = [
        'path',
        'url',
        'source',
        'medium',
        'campaign',
        'referrer',
        'currency',
        'event_name',
        'event_category',
        'revenue_minor',
    ];

    /** @var list<string> */
    public const SUPPORTED_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'starts_with',
        'ends_with',
        'greater_than',
        'greater_than_or_equal',
        'less_than',
        'less_than_or_equal',
        'in',
    ];

    /** @var list<string> */
    private const NUMERIC_DIRECT_FIELDS = [
        'revenue_minor',
    ];

    /** @var list<string> */
    private const NUMERIC_OPERATORS = [
        'greater_than',
        'greater_than_or_equal',
        'less_than',
        'less_than_or_equal',
    ];

    public static function isSupportedField(string $field): bool
    {
        if (in_array($field, self::SUPPORTED_DIRECT_FIELDS, true)) {
            return true;
        }

        return self::propertySegments($field) !== null;
    }

    public static function isSupportedOperator(string $operator): bool
    {
        return in_array($operator, self::SUPPORTED_OPERATORS, true);
    }

    public static function operatorRequiresNumericComparison(string $operator): bool
    {
        return in_array($operator, self::NUMERIC_OPERATORS, true);
    }

    public static function fieldSupportsNumericComparison(string $field): bool
    {
        return in_array($field, self::NUMERIC_DIRECT_FIELDS, true) || self::isPropertyField($field);
    }

    public static function isPropertyField(string $field): bool
    {
        return self::propertySegments($field) !== null;
    }

    /**
     * @return list<string>|null
     */
    public static function propertySegments(string $field): ?array
    {
        if (! str_starts_with($field, 'properties.')) {
            return null;
        }

        $segments = array_values(array_filter(explode('.', mb_substr($field, mb_strlen('properties.')))));

        if ($segments === []) {
            return null;
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                return null;
            }
        }

        return $segments;
    }
}
