<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Services\CommerceSignalsRecorder;
use AIArmada\Signals\Support\SignalEventMap;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
                'required_scalar' => is_scalar($value) ? (string) $value : null,
                'numeric_int' => is_numeric($value) ? (int) $value : 0,
                'required_numeric_int' => is_numeric($value) ? (int) $value : null,
                default => null,
            };

            if (in_array($argument['type'], ['required_scalar', 'required_numeric_int'], true)) {
                if ($value === null || $value === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Event [%s] is missing required property [%s].',
                        $event::class,
                        (string) $argument['property'],
                    ));
                }

                $arguments[] = $value;

                continue;
            }

            if ($argument['type'] !== 'event' && $argument['type'] !== 'scalar' && $value === null) {
                return;
            }

            if ($argument['type'] === 'model' && ! $value instanceof Model) {
                return;
            }

            $arguments[] = $value;
        }

        $this->recorder->{$mapping['method']}(...$arguments);
    }
}
