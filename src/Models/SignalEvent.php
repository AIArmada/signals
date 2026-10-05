<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Support\SignalOwnershipGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tracked_property_id
 * @property string|null $signal_session_id
 * @property string|null $signal_identity_id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property CarbonImmutable $occurred_at
 * @property string $event_name
 * @property string $event_category
 * @property string $ingestion_source
 * @property string|null $idempotency_key
 * @property string|null $source_event_id
 * @property string|null $path
 * @property string|null $url
 * @property string|null $referrer
 * @property string|null $source
 * @property string|null $medium
 * @property string|null $campaign
 * @property string|null $content
 * @property string|null $term
 * @property int $revenue_minor
 * @property string $currency
 * @property array<string, mixed>|null $properties
 * @property array<string, mixed>|null $property_types
 * @property-read TrackedProperty $trackedProperty
 * @property-read SignalSession|null $session
 * @property-read SignalIdentity|null $identity
 */
final class SignalEvent extends Model
{
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    public const string INGESTION_SOURCE_BROWSER = 'browser';

    public const string INGESTION_SOURCE_TRUSTED = 'trusted';

    protected static string $ownerScopeConfigKey = 'signals.owner';

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'signal_session_id',
        'signal_identity_id',
        'occurred_at',
        'event_name',
        'event_category',
        'ingestion_source',
        'idempotency_key',
        'source_event_id',
        'path',
        'url',
        'referrer',
        'source',
        'medium',
        'campaign',
        'content',
        'term',
        'revenue_minor',
        'currency',
        'properties',
        'property_types',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'revenue_minor' => 'integer',
        'properties' => 'array',
        'property_types' => 'array',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['events'] ?? $prefix . 'events';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    /**
     * @return BelongsTo<SignalSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SignalSession::class, 'signal_session_id');
    }

    /**
     * @return BelongsTo<SignalIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(SignalIdentity::class, 'signal_identity_id');
    }

    protected static function booted(): void
    {
        static::saving(function (SignalEvent $event): void {
            $event->ingestion_source ??= self::INGESTION_SOURCE_BROWSER;

            SignalOwnershipGuard::assertEventWrite($event);
        });
    }
}
