<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\AlertRuleAdministration;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertRule;
use AIArmada\Signals\Features\Alerts\Support\AlertDestinationPolicy;
use Illuminate\Support\Arr;

final readonly class AdministerAlertRule implements AlertRuleAdministration
{
    public function __construct(private AlertDestinationPolicy $destinations) {}

    public function create(array $data): string
    {
        return (new SignalAlertRule)->getConnection()->transaction(function () use ($data): string {
            $attributes = $this->attributes($data);
            $this->destinations->assertWritable(
                OwnerContext::resolve(),
                $this->channels($attributes),
                $this->keys($attributes),
            );

            $record = new SignalAlertRule($attributes);

            if (SignalAlertRule::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolveConfigured(SignalAlertRule::ownerScopeConfig());
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Signals administration requires an owner context.');

                if ($owner !== null) {
                    $record->owner()->associate($owner);
                }
            }

            $record->save();

            return (string) $record->getKey();
        }, attempts: 3);
    }

    public function update(string $id, array $data): void
    {
        (new SignalAlertRule)->getConnection()->transaction(function () use ($id, $data): void {
            $record = $this->lockRecord($id);
            $attributes = $this->attributes($data);
            $this->destinations->assertWritable(
                OwnerContext::resolve(),
                $this->channels($attributes, $record),
                $this->keys($attributes, $record),
            );

            $record->update($attributes);
        }, attempts: 3);
    }

    public function delete(string $id): bool
    {
        return (new SignalAlertRule)->getConnection()->transaction(
            fn (): bool => $this->lockRecord($id)->delete() === true,
            attempts: 3,
        );
    }

    private function lockRecord(string $id): SignalAlertRule
    {
        $query = SignalAlertRule::query();

        if (SignalAlertRule::ownerScopeConfig()->enabled) {
            $owner = OwnerContext::resolveConfigured(SignalAlertRule::ownerScopeConfig());
            OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Signals administration requires an owner context.');

            $query = $owner === null
                ? $query->globalOnly()
                : $query->forOwner($owner, includeGlobal: false);
        }

        return $query->lockForUpdate()->findOrFail($id);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return Arr::only($data, [
            'tracked_property_id', 'name', 'slug', 'description',
            'metric_key', 'operator', 'threshold', 'event_filters',
            'channels', 'destination_keys', 'inline_destinations', 'timeframe_minutes',
            'cooldown_minutes', 'severity', 'priority', 'is_active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, mixed>
     */
    private function channels(array $attributes, ?SignalAlertRule $record = null): array
    {
        $channels = array_key_exists('channels', $attributes)
            ? $attributes['channels']
            : $record?->channels;

        if (! is_array($channels) || $channels === []) {
            $channels = config('signals.features.alerts.default_channels', ['database']);
        }

        return is_array($channels) ? $channels : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, mixed>
     */
    private function keys(array $attributes, ?SignalAlertRule $record = null): array
    {
        $keys = array_key_exists('destination_keys', $attributes)
            ? $attributes['destination_keys']
            : $record?->destination_keys;

        return is_array($keys) ? $keys : [];
    }
}
