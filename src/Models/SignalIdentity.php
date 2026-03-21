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
 * @property string|null $external_id
 * @property string|null $anonymous_id
 * @property string|null $email
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property array<string, mixed>|null $traits
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 * @property-read TrackedProperty $trackedProperty
 * @property-read Collection<int, SignalSession> $sessions
 * @property-read Collection<int, SignalEvent> $events
 */
final class SignalIdentity extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'external_id',
        'anonymous_id',
        'email',
        'traits',
        'first_seen_at',
        'last_seen_at',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'traits' => 'array',
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['identities'] ?? $prefix . 'identities';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<SignalSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(SignalSession::class, 'signal_identity_id');
    }

    /**
     * @return HasMany<SignalEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SignalEvent::class, 'signal_identity_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (SignalIdentity $identity): void {
            $identity->sessions()->update(['signal_identity_id' => null]);
            $identity->events()->update(['signal_identity_id' => null]);
        });
    }
}
