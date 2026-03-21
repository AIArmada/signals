<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tracked_property_id
 * @property string|null $signal_identity_id
 * @property string|null $session_identifier
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int $duration_seconds
 * @property string|null $entry_path
 * @property string|null $exit_path
 * @property string|null $country
 * @property string|null $device_type
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $referrer
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property bool $is_bounce
 * @property-read TrackedProperty $trackedProperty
 * @property-read SignalIdentity|null $identity
 * @property-read Collection<int, SignalEvent> $events
 */
final class SignalSession extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'signal_identity_id',
        'session_identifier',
        'started_at',
        'ended_at',
        'duration_seconds',
        'entry_path',
        'exit_path',
        'country',
        'device_type',
        'browser',
        'os',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'is_bounce',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'duration_seconds' => 'integer',
        'is_bounce' => 'boolean',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['sessions'] ?? $prefix . 'sessions';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    /**
     * @return BelongsTo<SignalIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(SignalIdentity::class, 'signal_identity_id');
    }

    /**
     * @return HasMany<SignalEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SignalEvent::class, 'signal_session_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (SignalSession $session): void {
            $session->events()->update(['signal_session_id' => null]);
        });
    }
}
