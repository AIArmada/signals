<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SavedSignalReportDefinition
{
    public const ATTRIBUTION_MODEL_EVENT = 'event';

    public const ATTRIBUTION_MODEL_FIRST_TOUCH = 'first_touch';

    public const ATTRIBUTION_MODEL_LAST_TOUCH = 'last_touch';

    /** @var array<string, string> */
    private const REPORT_TYPES = [
        'dashboard' => 'Dashboard',
        'page_views' => 'Page Views',
        'conversion_funnel' => 'Conversion Funnel',
        'acquisition' => 'Acquisition',
        'journeys' => 'Journeys',
        'retention' => 'Retention',
        'content_performance' => 'Content Performance',
        'live_activity' => 'Live Activity',
    ];

    /** @var array<string, string> */
    private const FILTER_FIELDS = [
        'date_from' => 'From Date',
        'date_to' => 'To Date',
    ];

    /** @var array<string, string> */
    private const ATTRIBUTION_MODELS = [
        self::ATTRIBUTION_MODEL_EVENT => 'Event Touch',
        self::ATTRIBUTION_MODEL_FIRST_TOUCH => 'First Touch',
        self::ATTRIBUTION_MODEL_LAST_TOUCH => 'Last Touch',
    ];

    /** @var array<string, string> */
    private const JOURNEY_BREAKDOWN_DIMENSIONS = [
        'path_pair' => 'Path Pair',
        'entry_path' => 'Entry Path',
        'exit_path' => 'Exit Path',
        'country' => 'Country',
        'device_type' => 'Device Type',
        'browser' => 'Browser',
        'os' => 'Operating System',
        'utm_source' => 'Source',
        'utm_medium' => 'Medium',
        'utm_campaign' => 'Campaign',
    ];

    /** @var array<string, string> */
    private const CONTENT_BREAKDOWN_DIMENSIONS = [
        'path' => 'Path',
        'source' => 'Source',
        'medium' => 'Medium',
        'campaign' => 'Campaign',
        'referrer' => 'Referrer',
    ];

    /** @return array<string, string> */
    public static function reportTypeOptions(): array
    {
        return self::REPORT_TYPES;
    }

    /** @return array<string, string> */
    public static function filterFieldOptions(): array
    {
        return self::FILTER_FIELDS;
    }

    public static function isSupportedReportType(string $reportType): bool
    {
        return array_key_exists($reportType, self::REPORT_TYPES);
    }

    /** @return array<string, string> */
    public static function attributionModelOptions(): array
    {
        return self::ATTRIBUTION_MODELS;
    }

    /** @return array<string, string> */
    public static function journeyBreakdownDimensionOptions(): array
    {
        return self::JOURNEY_BREAKDOWN_DIMENSIONS;
    }

    /** @return array<string, string> */
    public static function contentBreakdownDimensionOptions(): array
    {
        return self::CONTENT_BREAKDOWN_DIMENSIONS;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<int, array{key:string,value:string}>|null
     */
    public static function normalizeFilters(?array $filters): ?array
    {
        if ($filters === null || $filters === []) {
            return null;
        }

        /** @var array<string, string> $normalized */
        $normalized = [];

        foreach ($filters as $index => $filter) {
            $key = mb_trim((string) ($filter['key'] ?? ''));
            $value = mb_trim((string) ($filter['value'] ?? ''));

            if ($key === '' || ! array_key_exists($key, self::FILTER_FIELDS)) {
                throw new InvalidArgumentException("Invalid saved report filter at index {$index}: unsupported key [{$key}].");
            }

            if ($value === '') {
                throw new InvalidArgumentException("Invalid saved report filter at index {$index}: value is required.");
            }

            $normalized[$key] = CarbonImmutable::parse($value)->toDateString();
        }

        if (isset($normalized['date_from'], $normalized['date_to']) && $normalized['date_from'] > $normalized['date_to']) {
            throw new InvalidArgumentException('Invalid saved report filters: date_from cannot be after date_to.');
        }

        /** @var array<int, array{key:string,value:string}> $normalizedList */
        $normalizedList = [];

        foreach (array_keys(self::FILTER_FIELDS) as $key) {
            if (! array_key_exists($key, $normalized)) {
                continue;
            }

            $normalizedList[] = [
                'key' => $key,
                'value' => $normalized[$key],
            ];
        }

        return $normalizedList === [] ? null : $normalizedList;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, string>
     */
    public static function filtersToMap(?array $filters): array
    {
        $normalizedFilters = self::normalizeFilters($filters);

        if ($normalizedFilters === null) {
            return [];
        }

        $map = [];

        foreach ($normalizedFilters as $filter) {
            $map[$filter['key']] = $filter['value'];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     * @return array<string, mixed>|null
     */
    public static function normalizeSettings(string $reportType, ?array $settings): ?array
    {
        if ($settings === null || $settings === []) {
            return null;
        }

        $structuredSettings = self::normalizeSettingsShape($settings);

        return match ($reportType) {
            'conversion_funnel' => self::normalizeFunnelSettings($structuredSettings),
            'acquisition' => self::normalizeAcquisitionSettings($structuredSettings),
            'journeys' => self::normalizeJourneySettings($structuredSettings),
            'content_performance' => self::normalizeContentPerformanceSettings($structuredSettings),
            'retention' => self::normalizeRetentionSettings($structuredSettings),
            default => $structuredSettings === [] ? null : $structuredSettings,
        };
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     * @return list<array{label:string,event_name:string,event_category:string|null}>
     */
    public static function funnelSteps(?array $settings): array
    {
        $structuredSettings = self::normalizeSettingsShape($settings);
        $steps = $structuredSettings['funnel_steps'] ?? [];

        if (! is_array($steps)) {
            return [];
        }

        /** @var list<array{label:string,event_name:string,event_category:string|null}> $normalizedSteps */
        $normalizedSteps = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $label = mb_trim((string) ($step['label'] ?? ''));
            $eventName = mb_trim((string) ($step['event_name'] ?? ''));

            if ($label === '' || $eventName === '') {
                continue;
            }

            $eventCategory = mb_trim((string) ($step['event_category'] ?? ''));

            $normalizedSteps[] = [
                'label' => $label,
                'event_name' => $eventName,
                'event_category' => $eventCategory !== '' ? $eventCategory : null,
            ];
        }

        return $normalizedSteps;
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     */
    public static function attributionModel(?array $settings): string
    {
        $structuredSettings = self::normalizeSettingsShape($settings);
        $model = (string) ($structuredSettings['attribution_model'] ?? self::ATTRIBUTION_MODEL_EVENT);

        return array_key_exists($model, self::ATTRIBUTION_MODELS)
            ? $model
            : self::ATTRIBUTION_MODEL_EVENT;
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     */
    public static function conversionEventName(?array $settings): string
    {
        $structuredSettings = self::normalizeSettingsShape($settings);
        $eventName = mb_trim((string) ($structuredSettings['conversion_event_name'] ?? ''));
        $defaultOutcomeEventName = mb_trim((string) config('signals.defaults.primary_outcome_event_name', ''));

        if ($eventName !== '') {
            return $eventName;
        }

        if ($defaultOutcomeEventName !== '') {
            return $defaultOutcomeEventName;
        }

        return (string) config('signals.integrations.orders.event_name', 'order.paid');
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     */
    public static function stepWindowMinutes(?array $settings): ?int
    {
        $structuredSettings = self::normalizeSettingsShape($settings);
        $value = $structuredSettings['step_window_minutes'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     */
    public static function journeyBreakdownDimension(?array $settings): string
    {
        $structuredSettings = self::normalizeSettingsShape($settings);
        $dimension = (string) ($structuredSettings['breakdown_dimension'] ?? 'path_pair');

        return array_key_exists($dimension, self::JOURNEY_BREAKDOWN_DIMENSIONS)
            ? $dimension
            : 'path_pair';
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     */
    public static function contentBreakdownDimension(?array $settings): string
    {
        $structuredSettings = self::normalizeSettingsShape($settings);
        $dimension = (string) ($structuredSettings['breakdown_dimension'] ?? 'path');

        return array_key_exists($dimension, self::CONTENT_BREAKDOWN_DIMENSIONS)
            ? $dimension
            : 'path';
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     * @return list<int>
     */
    public static function retentionWindows(?array $settings): array
    {
        $structuredSettings = self::normalizeSettingsShape($settings);
        $windows = $structuredSettings['retention_windows'] ?? null;
        $normalizedWindows = self::normalizeRetentionWindowValues($windows);

        return $normalizedWindows !== [] ? $normalizedWindows : [7, 30];
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null  $settings
     * @return array<string, mixed>
     */
    private static function normalizeSettingsShape(?array $settings): array
    {
        if ($settings === null || $settings === []) {
            return [];
        }

        $firstValue = reset($settings);

        if (is_array($firstValue) && array_key_exists('key', $firstValue) && array_key_exists('value', $firstValue)) {
            $mappedSettings = [];

            foreach ($settings as $setting) {
                if (! is_array($setting)) {
                    continue;
                }

                $key = mb_trim((string) ($setting['key'] ?? ''));

                if ($key === '') {
                    continue;
                }

                $mappedSettings[$key] = $setting['value'] ?? null;
            }

            return $mappedSettings;
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private static function normalizeFunnelSettings(array $settings): ?array
    {
        $steps = $settings['funnel_steps'] ?? [];

        if (! is_array($steps)) {
            throw new InvalidArgumentException('Invalid saved report settings: funnel_steps must be an array.');
        }

        $normalizedSteps = self::funnelSteps($settings);

        if ($steps !== [] && count($normalizedSteps) < 2) {
            throw new InvalidArgumentException('Invalid saved report settings: funnel reports require at least two valid steps.');
        }

        $normalized = [];

        if ($normalizedSteps !== []) {
            $normalized['funnel_steps'] = $normalizedSteps;
        }

        $stepWindowMinutes = self::stepWindowMinutes($settings);

        if ($stepWindowMinutes !== null) {
            $normalized['step_window_minutes'] = $stepWindowMinutes;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private static function normalizeAcquisitionSettings(array $settings): ?array
    {
        $attributionModel = self::attributionModel($settings);
        $conversionEventName = self::conversionEventName($settings);

        return [
            'attribution_model' => $attributionModel,
            'conversion_event_name' => $conversionEventName,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private static function normalizeJourneySettings(array $settings): ?array
    {
        $dimension = self::journeyBreakdownDimension($settings);

        return [
            'breakdown_dimension' => $dimension,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private static function normalizeContentPerformanceSettings(array $settings): ?array
    {
        $dimension = self::contentBreakdownDimension($settings);

        return [
            'breakdown_dimension' => $dimension,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private static function normalizeRetentionSettings(array $settings): ?array
    {
        $windows = self::normalizeRetentionWindowValues($settings['retention_windows'] ?? null);

        if ($windows === []) {
            return null;
        }

        return [
            'retention_windows' => $windows,
        ];
    }

    /**
     * @return list<int>
     */
    private static function normalizeRetentionWindowValues(mixed $windows): array
    {
        if ($windows === null || $windows === []) {
            return [];
        }

        if (! is_array($windows)) {
            throw new InvalidArgumentException('Invalid saved report settings: retention_windows must be an array.');
        }

        $normalizedWindows = [];

        foreach ($windows as $index => $window) {
            $rawDays = is_array($window) ? ($window['days'] ?? null) : $window;

            if ($rawDays === null || $rawDays === '') {
                continue;
            }

            $days = (int) $rawDays;

            if ($days <= 0) {
                throw new InvalidArgumentException("Invalid saved report settings: retention window at index {$index} must be a positive integer.");
            }

            $normalizedWindows[] = $days;
        }

        $normalizedWindows = array_values(array_unique($normalizedWindows));
        sort($normalizedWindows);

        return $normalizedWindows;
    }
}
