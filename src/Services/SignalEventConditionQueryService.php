<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class SignalEventConditionQueryService
{
    /**
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public function apply(Builder $query, ?array $conditions, string $matchType = 'all'): Builder
    {
        if ($conditions === null || $conditions === []) {
            return $query;
        }

        if (! in_array($matchType, ['all', 'any'], true)) {
            return $this->failClosed($query);
        }

        if ($matchType === 'all') {
            foreach ($conditions as $condition) {
                if (! is_array($condition)) {
                    return $this->failClosed($query);
                }

                if (! $this->applySingleCondition($query, $condition)) {
                    return $this->failClosed($query);
                }
            }

            return $query;
        }

        return $query->where(function (Builder $nestedQuery) use ($conditions): void {
            foreach ($conditions as $index => $condition) {
                if (! is_array($condition)) {
                    $this->failClosed($nestedQuery);

                    return;
                }

                $method = $index === 0 ? 'where' : 'orWhere';

                $nestedQuery->{$method}(function (Builder $conditionQuery) use ($condition): void {
                    if (! $this->applySingleCondition($conditionQuery, $condition)) {
                        $this->failClosed($conditionQuery);
                    }
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function applySingleCondition(Builder $query, array $condition): bool
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

        if (SignalEventConditionDefinition::operatorRequiresNumericComparison($operator) && ! SignalEventConditionDefinition::fieldSupportsNumericComparison($field)) {
            return false;
        }

        $inValues = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));

        if ($operator === 'in' && $inValues === []) {
            return false;
        }

        $propertySegments = SignalEventConditionDefinition::propertySegments($field);

        if ($propertySegments !== null) {
            return $this->applyPropertyCondition($query, $propertySegments, $operator, $value, $inValues);
        }

        $escapedLikeValue = $this->escapeLike($value);

        match ($operator) {
            'equals' => $query->where($field, $value),
            'not_equals' => $query->where($field, '!=', $value),
            'contains' => $query->where($field, 'like', '%' . $escapedLikeValue . '%'),
            'starts_with' => $query->where($field, 'like', $escapedLikeValue . '%'),
            'ends_with' => $query->where($field, 'like', '%' . $escapedLikeValue),
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
    private function applyPropertyCondition(Builder $query, array $propertySegments, string $operator, string $value, array $inValues): bool
    {
        $escapedLikeValue = $this->escapeLike($value);
        $textExpression = $this->jsonTextExpression($query, 'properties', $propertySegments);

        match ($operator) {
            'equals' => $query->whereRaw("{$textExpression} = ?", [$value]),
            'not_equals' => $query->whereRaw("{$textExpression} IS NOT NULL")
                ->whereRaw("{$textExpression} <> ?", [$value]),
            'contains' => $query->whereRaw("{$textExpression} LIKE ? ESCAPE '\\'", ['%' . $escapedLikeValue . '%']),
            'starts_with' => $query->whereRaw("{$textExpression} LIKE ? ESCAPE '\\'", [$escapedLikeValue . '%']),
            'ends_with' => $query->whereRaw("{$textExpression} LIKE ? ESCAPE '\\'", ['%' . $escapedLikeValue]),
            'greater_than' => $this->applyNumericPropertyComparison($query, $propertySegments, '>', $value),
            'greater_than_or_equal' => $this->applyNumericPropertyComparison($query, $propertySegments, '>=', $value),
            'less_than' => $this->applyNumericPropertyComparison($query, $propertySegments, '<', $value),
            'less_than_or_equal' => $this->applyNumericPropertyComparison($query, $propertySegments, '<=', $value),
            'in' => $this->applyPropertyInCondition($query, $textExpression, $inValues),
            default => throw new RuntimeException("Unsupported signal event condition operator [{$operator}]."),
        };

        return true;
    }

    /**
     * @param  list<string>  $propertySegments
     */
    private function applyNumericPropertyComparison(Builder $query, array $propertySegments, string $sqlOperator, string $value): void
    {
        if (! is_numeric($value)) {
            $this->failClosed($query);

            return;
        }

        $typeExpression = $this->jsonTextExpression($query, 'property_types', $propertySegments);
        $numericExpression = $this->castNumericExpression($query, $this->jsonTextExpression($query, 'properties', $propertySegments));

        $query
            ->whereRaw("{$typeExpression} = ?", ['number'])
            ->whereRaw("{$numericExpression} {$sqlOperator} ?", [$value]);
    }

    /**
     * @param  list<string>  $values
     */
    private function applyPropertyInCondition(Builder $query, string $expression, array $values): void
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $query->whereRaw("{$expression} IN ({$placeholders})", $values);
    }

    /**
     * @param  list<string>  $propertySegments
     */
    private function jsonTextExpression(Builder $query, string $column, array $propertySegments): string
    {
        $driver = $query->getConnection()->getDriverName();
        $jsonPath = '$.' . implode('.', $propertySegments);
        $postgresPath = '{' . implode(',', $propertySegments) . '}';

        return match ($driver) {
            'pgsql' => "{$column} #>> '{$postgresPath}'",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$jsonPath}'))",
            'sqlite' => "CAST(json_extract({$column}, '{$jsonPath}') AS TEXT)",
            default => throw new RuntimeException("Unsupported database driver [{$driver}] for Signals property queries."),
        };
    }

    private function castNumericExpression(Builder $query, string $expression): string
    {
        return match ($query->getConnection()->getDriverName()) {
            'pgsql' => "CAST({$expression} AS NUMERIC)",
            'mysql', 'mariadb', 'sqlite' => "CAST({$expression} AS DECIMAL(20, 6))",
            default => $expression,
        };
    }

    public function failClosed(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
