<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Models\Concerns\AutoAssignsSignalOwnerOnCreate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $signal_alert_rule_id
 * @property string|null $tracked_property_id
 * @property string $metric_key
 * @property string $operator
 * @property float $metric_value
 * @property float $threshold_value
 * @property string $severity
 * @property string $title
 * @property string|null $message
 * @property array<string, mixed>|null $context
 * @property array<int, string>|null $channels_notified
 * @property bool $is_read
 * @property Carbon|null $read_at
 * @property-read SignalAlertRule $alertRule
 * @property-read TrackedProperty|null $trackedProperty
 */
final class SignalAlertLog extends Model
{
    use AutoAssignsSignalOwnerOnCreate;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'signal_alert_rule_id',
        'tracked_property_id',
        'metric_key',
        'operator',
        'metric_value',
        'threshold_value',
        'severity',
        'title',
        'message',
        'context',
        'channels_notified',
        'is_read',
        'read_at',
        'owner_type',
        'owner_id',
    ];

    public function getTable(): string
    {
        $tables = config('signals.database.tables', []);
        $prefix = config('signals.database.table_prefix', 'signal_');

        return $tables['alert_logs'] ?? $prefix . 'alert_logs';
    }

    /**
     * @return BelongsTo<SignalAlertRule, $this>
     */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(SignalAlertRule::class, 'signal_alert_rule_id');
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    public function markAsRead(): void
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function markAsUnread(): void
    {
        $this->update([
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public static function ownerScopingEnabled(): bool
    {
        return static::resolveOwnerScopeConfig()->enabled;
    }

    protected static function booted(): void
    {
        static::saving(function (SignalAlertLog $log): void {
            if (! self::ownerScopingEnabled()) {
                return;
            }

            $owner = OwnerContext::resolve();

            if ($owner === null) {
                throw new RuntimeException('Owner scoping is enabled but no owner was resolved while saving a signal alert log.');
            }

            $ruleExists = SignalAlertRule::query()
                ->forOwner($owner, includeGlobal: false)
                ->whereKey($log->signal_alert_rule_id)
                ->exists();

            if (! $ruleExists) {
                throw new RuntimeException('Invalid signal_alert_rule_id: does not belong to the current owner scope.');
            }

            if ($log->tracked_property_id !== '' && $log->tracked_property_id !== null) {
                $propertyExists = TrackedProperty::query()
                    ->forOwner($owner, includeGlobal: false)
                    ->whereKey($log->tracked_property_id)
                    ->exists();

                if (! $propertyExists) {
                    throw new RuntimeException('Invalid tracked_property_id: does not belong to the current owner scope.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_value' => 'float',
            'threshold_value' => 'float',
            'context' => 'array',
            'channels_notified' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }
}
