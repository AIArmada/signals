<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $signal_alert_log_id
 * @property string $channel
 * @property array<string, mixed>|null $destination
 * @property string $status
 * @property int $attempt_count
 * @property int $max_attempts
 * @property CarbonImmutable|null $leased_at
 */
final class SignalAlertDelivery extends Model
{
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'signals.owner';

    protected $fillable = [
        'signal_alert_log_id',
        'channel',
        'destination_key',
        'destination',
        'status',
        'attempt_count',
        'max_attempts',
        'available_at',
        'leased_at',
        'last_attempt_at',
        'sent_at',
        'dead_at',
        'response_status',
        'last_error_code',
        'owner_type',
        'owner_id',
    ];

    protected $hidden = ['destination'];

    public function getTable(): string
    {
        return config('signals.database.tables.alert_deliveries', 'signal_alert_deliveries');
    }

    /** @return BelongsTo<SignalAlertLog, $this> */
    public function alertLog(): BelongsTo
    {
        return $this->belongsTo(SignalAlertLog::class, 'signal_alert_log_id');
    }

    protected function casts(): array
    {
        return [
            'destination' => 'array',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'leased_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'dead_at' => 'immutable_datetime',
            'response_status' => 'integer',
        ];
    }
}
