<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

/**
 * Privacy filter shared by event properties and identity traits.
 *
 * The PII blocklist applies recursively to every nested map and to maps
 * inside lists, so a blocked key cannot survive by nesting it. The
 * allowlist only gates top-level keys: an allowed container does not
 * exempt its descendants from the blocklist. Lists keep their shape and
 * drop entries that collapse to empty containers; maps drop keys that
 * collapse the same way. Traversal is bounded fail-closed: subtrees past
 * the depth bound are dropped, and keys past the node budget are dropped
 * without throwing.
 */
final class SignalPropertyFilter
{
    /** @var list<string> */
    private const BLOCKED_KEYS = [
        'email',
        'phone',
        'name',
        'first_name',
        'last_name',
        'customer_email',
        'customer_phone',
        'customer_name',
        'metadata',
        'cart_metadata',
    ];

    private const MAX_DEPTH = 10;

    private const MAX_NODES = 1000;

    /**
     * @param  array<string, mixed>|null  $properties
     * @return array<string, mixed>|null
     */
    public function filter(?array $properties): ?array
    {
        if ($properties === null) {
            return null;
        }

        $allowlist = config('signals.features.privacy.property_allowlist', []);
        $allowedKeys = is_array($allowlist) ? array_values(array_filter($allowlist, 'is_string')) : [];
        $allowAll = in_array('*', $allowedKeys, true);
        $nodes = 0;

        $filtered = $this->filterMap($properties, 1, $allowAll, $allowedKeys, $nodes);

        return $filtered === [] ? null : $filtered;
    }

    /**
     * @param  array<mixed>  $map
     * @param  list<string>  $allowedKeys
     * @return array<string, mixed>
     */
    private function filterMap(array $map, int $depth, bool $allowAll, array $allowedKeys, int &$nodes): array
    {
        $filtered = [];

        foreach ($map as $key => $value) {
            if ($nodes >= self::MAX_NODES) {
                break;
            }

            $nodes++;

            if (! is_string($key)) {
                continue;
            }

            if ($depth === 1 && ! $allowAll && ! in_array($key, $allowedKeys, true)) {
                continue;
            }

            if (in_array($key, self::BLOCKED_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                if ($depth + 1 > self::MAX_DEPTH) {
                    continue;
                }

                $filteredValue = array_is_list($value)
                    ? $this->filterList($value, $depth + 1, $nodes)
                    : $this->filterMap($value, $depth + 1, false, [], $nodes);

                if ($filteredValue === []) {
                    continue;
                }

                $filtered[$key] = $filteredValue;

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<mixed>  $list
     * @return list<mixed>
     */
    private function filterList(array $list, int $depth, int &$nodes): array
    {
        $filtered = [];

        foreach ($list as $item) {
            if ($nodes >= self::MAX_NODES) {
                break;
            }

            $nodes++;

            if (! is_array($item)) {
                if (is_scalar($item) || $item === null) {
                    $filtered[] = $item;
                }

                continue;
            }

            if ($depth + 1 > self::MAX_DEPTH) {
                continue;
            }

            $filteredItem = array_is_list($item)
                ? $this->filterList($item, $depth + 1, $nodes)
                : $this->filterMap($item, $depth + 1, false, [], $nodes);

            if ($filteredItem === []) {
                continue;
            }

            $filtered[] = $filteredItem;
        }

        return $filtered;
    }
}
