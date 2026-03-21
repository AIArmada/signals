<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use AIArmada\Signals\Services\SignalEventConditionDefinition;
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
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $goal_type
 * @property string $event_name
 * @property string|null $event_category
 * @property array<int, array<string, mixed>>|null $conditions
 * @property bool $is_active
 * @property-read TrackedProperty|null $trackedProperty
 */
final class SignalGoal extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'name',
        'slug',
        'description',
        'goal_type',
        'event_name',
        'event_category',
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

    public static function ownerScopingEnabled(): bool
    {
        return static::resolveOwnerScopeConfig()->enabled;
    }

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['goals'] ?? $prefix . 'goals';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    protected static function booted(): void
    {
        static::saving(function (SignalGoal $goal): void {
            if (! self::ownerScopingEnabled()) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner === null) {
                throw new RuntimeException('Owner scoping is enabled but no owner was resolved while saving a signal goal.');
            }

            if ($goal->tracked_property_id !== '' && $goal->tracked_property_id !== null) {
                $propertyExists = TrackedProperty::query()
                    ->forOwner($owner, includeGlobal: false)
                    ->whereKey($goal->tracked_property_id)
                    ->exists();

                if (! $propertyExists) {
                    throw new RuntimeException('Invalid tracked_property_id: does not belong to the current owner scope.');
                }
            }

            self::assertConditionsAreValid($goal);
        });
    }

    private static function assertConditionsAreValid(SignalGoal $goal): void
    {
        if ($goal->conditions === null) {
            return;
        }

        foreach ($goal->conditions as $index => $condition) {
            if (! is_array($condition)) {
                throw new InvalidArgumentException("Invalid goal condition at index {$index}: each condition must be an array.");
            }

            $field = is_string($condition['field'] ?? null) ? mb_trim($condition['field']) : '';
            $operator = is_string($condition['operator'] ?? null) ? mb_trim($condition['operator']) : '';
            $value = is_string($condition['value'] ?? null) ? mb_trim($condition['value']) : '';

            if ($field === '' || ! self::isSupportedField($field)) {
                throw new InvalidArgumentException("Invalid goal condition at index {$index}: unsupported field [{$field}].");
            }

            if ($operator === '' || ! SignalEventConditionDefinition::isSupportedOperator($operator)) {
                throw new InvalidArgumentException("Invalid goal condition at index {$index}: unsupported operator [{$operator}].");
            }

            if ($value === '') {
                throw new InvalidArgumentException("Invalid goal condition at index {$index}: value is required.");
            }

            if (SignalEventConditionDefinition::operatorRequiresNumericComparison($operator) && ! SignalEventConditionDefinition::fieldSupportsNumericComparison($field)) {
                throw new InvalidArgumentException("Invalid goal condition at index {$index}: operator [{$operator}] requires a numeric field.");
            }

            if ($operator === 'in' && array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '')) === []) {
                throw new InvalidArgumentException("Invalid goal condition at index {$index}: in-list conditions require at least one value.");
            }
        }
    }

    private static function isSupportedField(string $field): bool
    {
        return SignalEventConditionDefinition::isSupportedField($field);
    }
}
