<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;
use AIArmada\Signals\Support\SignalEventMap;
use Illuminate\Database\Eloquent\Model;

final class RecordCommerceSignal
{
    public function __construct(private readonly CommerceSignalsRecorder $recorder) {}

    public function handle(object $event): void
    {
        $mapping = SignalEventMap::for($event::class);

        if ($mapping === null) {
            return;
        }

        $arguments = [];

        foreach ($mapping['arguments'] as $argument) {
            $value = $argument['type'] === 'event'
                ? $event
                : (property_exists($event, (string) $argument['property']) ? $event->{$argument['property']} : null);

            $value = match ($argument['type']) {
                'event' => $value,
                'object' => is_object($value) ? $value : null,
                'model' => $value instanceof Model ? $value : null,
                'scalar' => $value === null || is_scalar($value) ? ($value === null ? null : (string) $value) : null,
                'numeric_int' => is_numeric($value) ? (int) $value : 0,
                default => null,
            };

            if ($argument['type'] !== 'event' && $argument['type'] !== 'scalar' && $value === null) {
                return;
            }

            if ($argument['type'] === 'model' && ! $value instanceof Model) {
                return;
            }

            if ($argument['type'] === 'scalar' && $argument['property'] !== 'transactionId' && $value === null) {
                // Nullable scalar fields retain each legacy listener's default.
                $value = null;
            }

            $arguments[] = $value;
        }

        $this->recorder->{$mapping['method']}(...$arguments);
    }
}
