<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use DateTimeInterface;

final class SignalEventPropertyTypeInferrer
{
    /**
     * @param  array<string, mixed>|null  $properties
     * @return array<string, mixed>|null
     */
    public function infer(?array $properties): ?array
    {
        if ($properties === null || $properties === []) {
            return null;
        }

        $types = $this->inferMap($properties);

        return $types === [] ? null : $types;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function inferMap(array $properties): array
    {
        $types = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $inferredType = $this->inferValue($value);

            if ($inferredType === null) {
                continue;
            }

            $types[$key] = $inferredType;
        }

        return $types;
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function inferValue(mixed $value): array | string | null
    {
        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if ($value instanceof DateTimeInterface) {
            return 'date';
        }

        if (is_string($value)) {
            return $this->looksLikeDate($value) ? 'date' : 'string';
        }

        if ($value === null) {
            return 'null';
        }

        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            return 'array';
        }

        $nestedTypes = $this->inferMap($value);

        return $nestedTypes === [] ? 'object' : $nestedTypes;
    }

    private function looksLikeDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            return false;
        }

        return strtotime($value) !== false;
    }
}
