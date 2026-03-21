<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use AIArmada\Signals\Services\SavedSignalReportDefinition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $tracked_property_id
 * @property string|null $signal_segment_id
 * @property string $name
 * @property string $slug
 * @property string $report_type
 * @property string|null $description
 * @property array<int, array<string, mixed>>|null $filters
 * @property array<string, mixed>|array<int, array<string, mixed>>|null $settings
 * @property bool $is_shared
 * @property bool $is_active
 * @property-read TrackedProperty|null $trackedProperty
 * @property-read SignalSegment|null $segment
 */
final class SavedSignalReport extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'signal_segment_id',
        'name',
        'slug',
        'report_type',
        'description',
        'filters',
        'settings',
        'is_shared',
        'is_active',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'filters' => 'array',
        'settings' => 'array',
        'is_shared' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function ownerScopingEnabled(): bool
    {
        return static::resolveOwnerScopeConfig()->enabled;
    }

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['saved_reports'] ?? $prefix . 'saved_reports';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    /**
     * @return BelongsTo<SignalSegment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(SignalSegment::class, 'signal_segment_id');
    }

    protected static function booted(): void
    {
        static::saving(function (SavedSignalReport $report): void {
            if (! SavedSignalReportDefinition::isSupportedReportType($report->report_type)) {
                throw new InvalidArgumentException("Invalid saved signal report type [{$report->report_type}].");
            }

            $report->filters = SavedSignalReportDefinition::normalizeFilters($report->filters);
            $report->settings = SavedSignalReportDefinition::normalizeSettings($report->report_type, $report->settings);

            if (! self::ownerScopingEnabled()) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner === null) {
                throw new RuntimeException('Owner scoping is enabled but no owner was resolved while saving a saved signal report.');
            }

            if ($report->tracked_property_id !== '' && $report->tracked_property_id !== null) {
                $propertyExists = TrackedProperty::query()
                    ->forOwner($owner, includeGlobal: false)
                    ->whereKey($report->tracked_property_id)
                    ->exists();

                if (! $propertyExists) {
                    throw new RuntimeException('Invalid tracked_property_id: does not belong to the current owner scope.');
                }
            }

            if ($report->signal_segment_id !== '' && $report->signal_segment_id !== null) {
                $segmentExists = SignalSegment::query()
                    ->forOwner($owner, includeGlobal: false)
                    ->whereKey($report->signal_segment_id)
                    ->exists();

                if (! $segmentExists) {
                    throw new RuntimeException('Invalid signal_segment_id: does not belong to the current owner scope.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function normalizedFilters(): array
    {
        return SavedSignalReportDefinition::filtersToMap($this->filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizedSettings(): array
    {
        return SavedSignalReportDefinition::normalizeSettings($this->report_type, $this->settings) ?? [];
    }
}
