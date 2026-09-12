<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use AIArmada\CommerceSupport\Support\ConnectionDriver;
use AIArmada\Signals\Models\SignalEvent;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Canonical condition definition, in-memory matcher, and SQL compiler.
 */
final class SignalCondition
{
    /** @var list<string> */
    public const SUPPORTED_DIRECT_FIELDS = [
        'path', 'url', 'source', 'medium', 'campaign', 'referrer', 'currency',
        'event_name', 'event_category', 'revenue_minor',
    ];

    /** @var list<string> */
    public const SUPPORTED_OPERATORS = [
        'equals', 'not_equals', 'contains', 'starts_with', 'ends_with',
        'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal', 'in',
    ];

    /** @var list<string> */
    private const NUMERIC_DIRECT_FIELDS = ['revenue_minor'];

    /** @var list<string> */
    private const NUMERIC_OPERATORS = [
        'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal',
    ];

    public static function isSupportedField(string $field): bool
    {
        return in_array($field, self::SUPPORTED_DIRECT_FIELDS, true) || self::propertySegments($field) !== null;
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

    /**
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public static function matches(SignalEvent $event, ?array $conditions, string $matchType = 'all'): bool
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

            $results[] = self::matchesSingle($event, $condition);
        }

        return $matchType === 'all'
            ? ! in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public static function applyToQuery(Builder $query, ?array $conditions, string $matchType = 'all'): Builder
    {
        if ($conditions === null || $conditions === []) {
            return $query;
        }

        if (! in_array($matchType, ['all', 'any'], true)) {
            return self::failClosed($query);
        }

        if ($matchType === 'all') {
            foreach ($conditions as $condition) {
                if (! is_array($condition) || ! self::applySingle($query, $condition)) {
                    return self::failClosed($query);
                }
            }

            return $query;
        }

        return $query->where(function (Builder $nestedQuery) use ($conditions): void {
            foreach ($conditions as $index => $condition) {
                if (! is_array($condition)) {
                    self::failClosed($nestedQuery);

                    return;
                }

                $method = $index === 0 ? 'where' : 'orWhere';
                $nestedQuery->{$method}(function (Builder $conditionQuery) use ($condition): void {
                    if (! self::applySingle($conditionQuery, $condition)) {
                        self::failClosed($conditionQuery);
                    }
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function matchesSingle(SignalEvent $event, array $condition): bool
    {
        [$field, $operator, $value] = self::normalizedCondition($condition);

        if ($field === null || $operator === null || $value === null || $value === '') {
            return false;
        }

        if (! self::isSupportedField($field) || ! self::isSupportedOperator($operator)) {
            return false;
        }

        if (self::operatorRequiresNumericComparison($operator) && ! self::fieldSupportsNumericComparison($field)) {
            return false;
        }

        $actualValue = self::resolveFieldValue($event, $field);

        if ($operator === 'in') {
            $values = self::inValues($value);

            return $actualValue !== null && in_array((string) $actualValue, $values, true);
        }

        if (self::operatorRequiresNumericComparison($operator)) {
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

    /**
     * @param  array<string, mixed>  $condition
     * @return array{string|null, string|null, string|null}
     */
    private static function normalizedCondition(array $condition): array
    {
        return [
            is_string($condition['field'] ?? null) ? $condition['field'] : null,
            is_string($condition['operator'] ?? null) ? $condition['operator'] : null,
            is_string($condition['value'] ?? null) ? mb_trim($condition['value']) : null,
        ];
    }

    private static function resolveFieldValue(SignalEvent $event, string $field): string | int | float | null
    {
        $propertySegments = self::propertySegments($field);

        if ($propertySegments !== null) {
            $value = data_get($event->properties, implode('.', $propertySegments));

            return is_scalar($value) ? $value : null;
        }

        $value = data_get($event, $field);

        return is_scalar($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function applySingle(Builder $query, array $condition): bool
    {
        [$field, $operator, $value] = self::normalizedCondition($condition);

        if ($field === null || $operator === null || $value === null || $value === '') {
            return false;
        }

        if (! self::isSupportedField($field) || ! self::isSupportedOperator($operator)) {
            return false;
        }

        if (self::operatorRequiresNumericComparison($operator) && ! self::fieldSupportsNumericComparison($field)) {
            return false;
        }

        $inValues = self::inValues($value);

        if ($operator === 'in' && $inValues === []) {
            return false;
        }

        $propertySegments = self::propertySegments($field);

        if ($propertySegments !== null) {
            return self::applyProperty($query, $propertySegments, $operator, $value, $inValues);
        }

        $escapedLikeValue = self::escapeLike($value);
        $likeOperator = ConnectionDriver::name($query->getConnection()) === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';

        match ($operator) {
            'equals' => $query->where($field, $value),
            'not_equals' => $query->where($field, '!=', $value),
            'contains' => $query->where($field, $likeOperator, '%' . $escapedLikeValue . '%'),
            'starts_with' => $query->where($field, $likeOperator, $escapedLikeValue . '%'),
            'ends_with' => $query->where($field, $likeOperator, '%' . $escapedLikeValue),
            'greater_than' => $query->where($field, '>', $value),
            'greater_than_or_equal' => $query->where($field, '>=', $value),
            'less_than' => $query->where($field, '<', $value),
            'less_than_or_equal' => $query->where($field, '<=', $value),
            'in' => $query->whereIn($field, $inValues),
            default => throw new RuntimeException("Unsupported signal event condition operator [{$operator}]."),
        };

        return true;
    }

    /**
     * @param  list<string>  $propertySegments
     * @param  list<string>  $inValues
     */
    private static function applyProperty(Builder $query, array $propertySegments, string $operator, string $value, array $inValues): bool
    {
        $escapedLikeValue = self::escapeLike($value);
        $textExpression = self::jsonTextExpression($query, 'properties', $propertySegments);
        $likeOperator = ConnectionDriver::name($query->getConnection()) === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';

        match ($operator) {
            'equals' => $query->whereRaw("{$textExpression} = ?", [$value]),
            'not_equals' => $query->whereRaw("{$textExpression} IS NOT NULL")->whereRaw("{$textExpression} <> ?", [$value]),
            'contains' => $query->whereRaw("{$textExpression} {$likeOperator} ? ESCAPE '\\'", ['%' . $escapedLikeValue . '%']),
            'starts_with' => $query->whereRaw("{$textExpression} {$likeOperator} ? ESCAPE '\\'", [$escapedLikeValue . '%']),
            'ends_with' => $query->whereRaw("{$textExpression} {$likeOperator} ? ESCAPE '\\'", ['%' . $escapedLikeValue]),
            'greater_than' => self::applyNumericProperty($query, $propertySegments, '>', $value),
            'greater_than_or_equal' => self::applyNumericProperty($query, $propertySegments, '>=', $value),
            'less_than' => self::applyNumericProperty($query, $propertySegments, '<', $value),
            'less_than_or_equal' => self::applyNumericProperty($query, $propertySegments, '<=', $value),
            'in' => self::applyPropertyIn($query, $textExpression, $inValues),
            default => throw new RuntimeException("Unsupported signal event condition operator [{$operator}]."),
        };

        return true;
    }

    /**
     * @param  list<string>  $propertySegments
     */
    private static function applyNumericProperty(Builder $query, array $propertySegments, string $sqlOperator, string $value): void
    {
        if (! is_numeric($value)) {
            self::failClosed($query);

            return;
        }

        $typeExpression = self::jsonTextExpression($query, 'property_types', $propertySegments);
        $numericExpression = self::castNumericExpression($query, self::jsonTextExpression($query, 'properties', $propertySegments));

        $query
            ->whereRaw("{$typeExpression} = ?", ['number'])
            ->whereRaw("{$numericExpression} {$sqlOperator} ?", [$value]);
    }

    /** @param list<string> $values */
    private static function applyPropertyIn(Builder $query, string $expression, array $values): void
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $query->whereRaw("{$expression} IN ({$placeholders})", $values);
    }

    /** @param list<string> $propertySegments */
    private static function jsonTextExpression(Builder $query, string $column, array $propertySegments): string
    {
        $driver = ConnectionDriver::name($query->getConnection());
        $jsonPath = '$.' . implode('.', $propertySegments);
        $postgresPath = '{' . implode(',', $propertySegments) . '}';

        return match ($driver) {
            'pgsql' => "{$column} #>> '{$postgresPath}'",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$jsonPath}'))",
            'sqlite' => "CAST(json_extract({$column}, '{$jsonPath}') AS TEXT)",
            default => throw new RuntimeException("Unsupported database driver [{$driver}] for Signals property queries."),
        };
    }

    private static function castNumericExpression(Builder $query, string $expression): string
    {
        return match (ConnectionDriver::name($query->getConnection())) {
            'pgsql' => "CAST({$expression} AS NUMERIC)",
            'mysql', 'mariadb', 'sqlite' => "CAST({$expression} AS DECIMAL(20, 6))",
            default => $expression,
        };
    }

    private static function failClosed(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }

    /** @return list<string> */
    private static function inValues(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
