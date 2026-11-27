<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Reporting\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Signals\Features\Ingestion\Api\TelemetryRead;
use AIArmada\Signals\Support\SignalCurrency;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
 * @property string $currency
 */
final class SignalDailyMetric extends Model
{
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected $table = 'signal_daily_metrics';

    protected static string $ownerScopeConfigKey = 'signals.owner';

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
        'currency',
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

    protected static function booted(): void
    {
        static::saving(function (SignalDailyMetric $metric): void {
            $propertyCurrency = app(TelemetryRead::class)->propertyCurrencyForWrite((string) $metric->tracked_property_id);
            $metricCurrency = SignalCurrency::normalize($metric->currency);

            if ($metricCurrency !== null && $metricCurrency !== $propertyCurrency && (int) $metric->revenue_minor !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'Signal daily metric revenue in [%s] cannot be recorded on a tracked property reporting in [%s].',
                    $metricCurrency,
                    $propertyCurrency,
                ));
            }

            $metric->currency = $propertyCurrency;
        });
    }
}
