<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tracked_property_id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $date
 * @property int $unique_identities
 * @property int $sessions
 * @property int $bounced_sessions
 * @property int $page_views
 * @property int $events
 * @property int $conversions
 * @property int $revenue_minor
 * @property-read TrackedProperty $trackedProperty
 */
final class SignalDailyMetric extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'date',
        'unique_identities',
        'sessions',
        'bounced_sessions',
        'page_views',
        'events',
        'conversions',
        'revenue_minor',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'unique_identities' => 'integer',
        'sessions' => 'integer',
        'bounced_sessions' => 'integer',
        'page_views' => 'integer',
        'events' => 'integer',
        'conversions' => 'integer',
        'revenue_minor' => 'integer',
        'date' => 'date:Y-m-d',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['daily_metrics'] ?? $prefix . 'daily_metrics';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }
}
