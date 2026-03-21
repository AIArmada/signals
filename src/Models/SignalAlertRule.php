<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $tracked_property_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $metric_key
 * @property string $operator
 * @property float $threshold
 * @property int $timeframe_minutes
 * @property int $cooldown_minutes
 * @property string $severity
 * @property int $priority
 * @property Carbon|null $last_triggered_at
 * @property bool $is_active
 * @property-read TrackedProperty|null $trackedProperty
 * @property-read Collection<int, SignalAlertLog> $logs
 */
final class SignalAlertRule extends Model
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
        'metric_key',
        'operator',
        'threshold',
        'timeframe_minutes',
        'cooldown_minutes',
        'severity',
        'priority',
        'last_triggered_at',
        'is_active',
        'owner_type',
        'owner_id',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['alert_rules'] ?? $prefix . 'alert_rules';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalAlertLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(SignalAlertLog::class, 'signal_alert_rule_id');
    }

    public function isInCooldown(): bool
    {
        if ($this->last_triggered_at === null) {
            return false;
        }

        return $this->last_triggered_at->copy()->addMinutes($this->cooldown_minutes)->isFuture();
    }

    public function getCooldownRemainingMinutes(): int
    {
        if (! $this->isInCooldown() || $this->last_triggered_at === null) {
            return 0;
        }

        return (int) now()->diffInMinutes($this->last_triggered_at->copy()->addMinutes($this->cooldown_minutes));
    }

    public function markTriggered(): void
    {
        $this->update(['last_triggered_at' => now()]);
    }

    public static function ownerScopingEnabled(): bool
    {
        return static::resolveOwnerScopeConfig()->enabled;
    }

    protected static function booted(): void
    {
        static::saving(function (SignalAlertRule $rule): void {
            if (! self::ownerScopingEnabled()) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner === null) {
                throw new RuntimeException('Owner scoping is enabled but no owner was resolved while saving a signal alert rule.');
            }

            if ($rule->tracked_property_id !== '' && $rule->tracked_property_id !== null) {
                $exists = TrackedProperty::query()
                    ->forOwner($owner, includeGlobal: false)
                    ->whereKey($rule->tracked_property_id)
                    ->exists();

                if (! $exists) {
                    throw new RuntimeException('Invalid tracked_property_id: does not belong to the current owner scope.');
                }
            }
        });

        static::deleting(function (SignalAlertRule $rule): void {
            $rule->logs()->delete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'timeframe_minutes' => 'integer',
            'cooldown_minutes' => 'integer',
            'priority' => 'integer',
            'last_triggered_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
