<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Support;

use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AlertDestinationPolicy
{
    /**
     * @param  array<int, mixed>  $channels
     * @param  array<int, mixed>  $keys
     */
    public function assertWritable(?Model $owner, array $channels, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        if (SignalAlertRule::ownerScopingEnabled() && $owner !== null) {
            throw new InvalidArgumentException('Named alert destinations are only available to global alert rules when owner scoping is enabled.');
        }

        $configured = config('signals.features.alerts.destinations', []);
        $configured = is_array($configured) ? $configured : [];
        $channels = array_values(array_unique(array_filter($channels, static fn (mixed $channel): bool => is_string($channel) && $channel !== '')));

        if (! in_array('database', $channels, true)) {
            $channels[] = 'database';
        }

        foreach ($channels as $channel) {
            if ($channel === 'database') {
                continue;
            }

            $destinations = $configured[$channel] ?? [];
            $destinations = is_array($destinations) ? $destinations : [];

            foreach ($keys as $key) {
                if (! is_string($key) || $key === '' || ! is_array($destinations[$key] ?? null)) {
                    throw new InvalidArgumentException("Unknown alert destination [{$key}] for channel [{$channel}].");
                }
            }
        }
    }

    public function configuredDestinationsAllowedForRule(SignalAlertRule $rule): bool
    {
        return ! SignalAlertRule::ownerScopingEnabled()
            || ($rule->owner_type === null && $rule->owner_id === null);
    }
}
