<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $name
 * @property string $slug
 * @property string $write_key
 * @property string|null $domain
 * @property string $type
 * @property string $timezone
 * @property string $currency
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property-read Collection<int, SignalIdentity> $identities
 * @property-read Collection<int, SignalSession> $sessions
 * @property-read Collection<int, SignalEvent> $events
 * @property-read Collection<int, SignalDailyMetric> $dailyMetrics
 * @property-read Collection<int, SignalGoal> $goals
 * @property-read Collection<int, SavedSignalReport> $savedReports
 * @property-read Collection<int, SignalAlertRule> $alertRules
 * @property-read Collection<int, SignalAlertLog> $alertLogs
 */
final class TrackedProperty extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'write_key',
        'domain',
        'type',
        'timezone',
        'currency',
        'is_active',
        'settings',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['tracked_properties'] ?? $prefix . 'tracked_properties';
    }

    /**
     * @return HasMany<SignalIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(SignalIdentity::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(SignalSession::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SignalEvent::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalDailyMetric, $this>
     */
    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(SignalDailyMetric::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(SignalGoal::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SavedSignalReport, $this>
     */
    public function savedReports(): HasMany
    {
        return $this->hasMany(SavedSignalReport::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalAlertRule, $this>
     */
    public function alertRules(): HasMany
    {
        return $this->hasMany(SignalAlertRule::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalAlertLog, $this>
     */
    public function alertLogs(): HasMany
    {
        return $this->hasMany(SignalAlertLog::class, 'tracked_property_id');
    }

    protected static function booted(): void
    {
        static::creating(function (TrackedProperty $trackedProperty): void {
            if (is_string($trackedProperty->write_key ?? null) && $trackedProperty->write_key !== '') {
                return;
            }

            $trackedProperty->write_key = Str::random(40);
        });

        static::deleting(function (TrackedProperty $trackedProperty): void {
            $trackedProperty->goals()->update(['tracked_property_id' => null]);
            $trackedProperty->savedReports()->update(['tracked_property_id' => null]);
            $trackedProperty->alertRules()->update(['tracked_property_id' => null]);
            $trackedProperty->alertLogs()->update(['tracked_property_id' => null]);
            $trackedProperty->dailyMetrics()->delete();
            $trackedProperty->events()->delete();
            $trackedProperty->sessions()->delete();
            $trackedProperty->identities()->delete();
        });
    }
}
