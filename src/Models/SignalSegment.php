<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use AIArmada\Signals\Services\SignalEventConditionDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use RuntimeException;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $match_type
 * @property array<int, array<string, mixed>>|null $conditions
 * @property bool $is_active
 * @property-read Collection<int, SavedSignalReport> $savedReports
 */
final class SignalSegment extends Model
{
    /** @var list<string> */
    private const SUPPORTED_MATCH_TYPES = [
        'all',
        'any',
    ];

    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.owner';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'match_type',
        'conditions',
        'is_active',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'conditions' => 'array',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'match_type' => 'all',
        'is_active' => true,
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['segments'] ?? $prefix . 'segments';
    }

    /**
     * @return HasMany<SavedSignalReport, $this>
     */
    public function savedReports(): HasMany
    {
        return $this->hasMany(SavedSignalReport::class, 'signal_segment_id');
    }

    protected static function booted(): void
    {
        static::saving(function (SignalSegment $segment): void {
            if (! self::ownerScopingEnabled()) {
                self::assertSegmentDefinitionIsValid($segment);

                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner === null) {
                throw new RuntimeException('Owner scoping is enabled but no owner was resolved while saving a signal segment.');
            }

            self::assertSegmentDefinitionIsValid($segment);
        });

        static::deleting(function (SignalSegment $segment): void {
            $segment->savedReports()->update(['signal_segment_id' => null]);
        });
    }

    public static function ownerScopingEnabled(): bool
    {
        return static::resolveOwnerScopeConfig()->enabled;
    }

    private static function assertSegmentDefinitionIsValid(SignalSegment $segment): void
    {
        if (! in_array($segment->match_type, self::SUPPORTED_MATCH_TYPES, true)) {
            throw new InvalidArgumentException("Invalid signal segment match type [{$segment->match_type}].");
        }

        if ($segment->conditions === null) {
            return;
        }

        foreach ($segment->conditions as $index => $condition) {
            if (! is_array($condition)) {
                throw new InvalidArgumentException("Invalid signal segment condition at index {$index}: each condition must be an array.");
            }

            $field = is_string($condition['field'] ?? null) ? mb_trim($condition['field']) : '';
            $operator = is_string($condition['operator'] ?? null) ? mb_trim($condition['operator']) : '';
            $value = is_string($condition['value'] ?? null) ? mb_trim($condition['value']) : '';

            if ($field === '' || ! self::isSupportedField($field)) {
                throw new InvalidArgumentException("Invalid signal segment condition at index {$index}: unsupported field [{$field}].");
            }

            if ($operator === '' || ! SignalEventConditionDefinition::isSupportedOperator($operator)) {
                throw new InvalidArgumentException("Invalid signal segment condition at index {$index}: unsupported operator [{$operator}].");
            }

            if ($value === '') {
                throw new InvalidArgumentException("Invalid signal segment condition at index {$index}: value is required.");
            }

            if (SignalEventConditionDefinition::operatorRequiresNumericComparison($operator) && ! SignalEventConditionDefinition::fieldSupportsNumericComparison($field)) {
                throw new InvalidArgumentException("Invalid signal segment condition at index {$index}: operator [{$operator}] requires a numeric field.");
            }

            if ($operator === 'in' && array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '')) === []) {
                throw new InvalidArgumentException("Invalid signal segment condition at index {$index}: in-list conditions require at least one value.");
            }
        }
    }

    private static function isSupportedField(string $field): bool
    {
        return SignalEventConditionDefinition::isSupportedField($field);
    }
}
