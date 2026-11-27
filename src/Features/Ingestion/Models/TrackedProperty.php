<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeKey;
use AIArmada\Signals\Features\Ingestion\Actions\CleanUpTrackedProperty;
use AIArmada\Signals\Support\SignalCurrency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $owner_scope
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
 * @property-read Collection<int, SignalInteractionRule> $interactionRules
 */
final class TrackedProperty extends Model implements Auditable
{
    use HasCommerceAudit;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasOwnerScopeKey;
    use HasUuids;
    use LogsCommerceActivity;

    protected $table = 'signal_tracked_properties';

    protected static string $ownerScopeConfigKey = 'signals.owner';

    /** @var list<string> */
    protected $hidden = [
        'owner_scope',
    ];

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

    public function getAuditInclude(): array
    {
        return [
            'name',
            'slug',
            'domain',
            'type',
            'timezone',
            'currency',
            'is_active',
            'settings',
            'owner_type',
            'owner_id',
        ];
    }

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
     * @return HasMany<SignalInteractionRule, $this>
     */
    public function interactionRules(): HasMany
    {
        return $this->hasMany(SignalInteractionRule::class, 'tracked_property_id');
    }

    protected static function booted(): void
    {
        static::creating(function (TrackedProperty $trackedProperty): void {
            $trackedProperty->currency = SignalCurrency::code($trackedProperty->currency);

            if (is_string($trackedProperty->write_key ?? null) && $trackedProperty->write_key !== '') {
                return;
            }

            $trackedProperty->write_key = Str::random(40);
        });

        static::updating(function (TrackedProperty $trackedProperty): void {
            if ($trackedProperty->isDirty('currency')) {
                throw new InvalidArgumentException('The tracked property reporting currency is immutable once created.');
            }
        });

        static::deleting(function (TrackedProperty $trackedProperty): void {
            app(CleanUpTrackedProperty::class)->handle($trackedProperty);
        });
    }

    /**
     * @return array<int, string>
     */
    protected function getLoggableAttributes(): array
    {
        return $this->getAuditInclude();
    }

    protected function getActivityLogName(): string
    {
        return 'signals';
    }
}
