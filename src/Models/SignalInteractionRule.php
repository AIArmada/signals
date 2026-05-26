<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeKey;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $owner_scope
 * @property string|null $tracked_property_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $trigger_type
 * @property string $event_name
 * @property string|null $event_category
 * @property string|null $selector
 * @property string|null $page_pattern
 * @property array<string, mixed>|null $settings
 * @property int $sort_order
 * @property bool $is_active
 * @property TrackedProperty|null $trackedProperty
 */
final class SignalInteractionRule extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasOwnerScopeKey;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.owner';

    /** @var list<string> */
    protected $hidden = [
        'owner_scope',
    ];

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'name',
        'slug',
        'description',
        'trigger_type',
        'event_name',
        'event_category',
        'selector',
        'page_pattern',
        'settings',
        'sort_order',
        'is_active',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'settings' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['interaction_rules'] ?? $prefix . 'interaction_rules';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }
}
