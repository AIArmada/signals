<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;
use stdClass;

/**
 * Shared currency-explicit revenue helpers for Signals reporting.
 *
 * Revenue is always presented either as a scalar amount together with the
 * reporting currency of a single tracked property, or as a map keyed by
 * currency when the scope spans multiple properties.
 */
final class SignalReportRevenue
{
    /**
     * @param  array<string, int>  $revenueByCurrency
     * @return array{revenue_minor:int, currency:string}|array{revenue:array<string, int>}
     */
    public static function shape(?string $trackedPropertyId, array $revenueByCurrency, ?string $propertyCurrency = null): array
    {
        if ($trackedPropertyId === null || $trackedPropertyId === '') {
            return ['revenue' => $revenueByCurrency];
        }

        $currency = $propertyCurrency ?? self::propertyCurrency($trackedPropertyId);

        return [
            'revenue_minor' => $revenueByCurrency[$currency] ?? 0,
            'currency' => $currency,
        ];
    }

    public static function propertyCurrency(?string $trackedPropertyId): string
    {
        if ($trackedPropertyId === null || $trackedPropertyId === '') {
            return SignalCurrency::default();
        }

        $property = TrackedProperty::query()
            ->forOwner()
            ->whereKey($trackedPropertyId)
            ->first();

        return SignalCurrency::code($property?->currency);
    }

    /**
     * @param  Builder<SignalEvent>  $query
     * @return array<string, int>
     */
    public static function byCurrency(Builder $query): array
    {
        $rows = $query
            ->selectRaw('UPPER(currency) as currency')
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) as revenue_minor')
            ->groupByRaw('UPPER(currency)')
            ->toBase()
            ->get();

        return self::fromCurrencyRows($rows->all());
    }

    /**
     * @param  Builder<SignalEvent>  $query
     */
    public static function sumForCurrency(Builder $query, string $currency): int
    {
        return (int) $query
            ->whereRaw('UPPER(currency) = ?', [$currency])
            ->sum('revenue_minor');
    }

    /**
     * SQL aggregate summing event revenue only when the event currency
     * matches its tracked property's reporting currency. Legacy
     * foreign-currency rows contribute zero instead of silently merging
     * into the property total.
     */
    public static function conditionalRevenueExpression(): string
    {
        $events = (new SignalEvent)->getTable();
        $properties = (new TrackedProperty)->getTable();

        return sprintf(
            "SUM(CASE WHEN UPPER(%1\$s.currency) = COALESCE((SELECT UPPER(%2\$s.currency) FROM %2\$s WHERE %2\$s.id = %1\$s.tracked_property_id), '') THEN %1\$s.revenue_minor ELSE 0 END)",
            $events,
            $properties,
        );
    }

    /**
     * @param  array<int, stdClass>  $rows
     * @return array<string, int>
     */
    public static function fromCurrencyRows(array $rows): array
    {
        $revenue = [];

        foreach ($rows as $row) {
            $currency = SignalCurrency::normalize($row->currency ?? null);

            if ($currency === null) {
                continue;
            }

            $revenue[$currency] = ($revenue[$currency] ?? 0) + (int) ($row->revenue_minor ?? 0);
        }

        ksort($revenue);

        return $revenue;
    }
}
