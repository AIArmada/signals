<?php

declare(strict_types=1);

namespace AIArmada\Signals\Listeners;

use AIArmada\Signals\Contracts\MapCommerceEventToSignalInterface;
use AIArmada\Signals\Services\CommerceSignalsRecorder;
use Illuminate\Contracts\Container\Container;

final class RecordSignalFromEvent
{
    /** @var array<class-string, MapCommerceEventToSignalInterface> */
    private array $mapperCache = [];

    public function __construct(
        private readonly CommerceSignalsRecorder $recorder,
        private readonly Container $container,
    ) {}

    public function handle(object $event): void
    {
        $mapper = $this->resolveMapper($event);

        if ($mapper === null) {
            return;
        }

        $result = $mapper->map($event);

        if ($result === null) {
            return;
        }

        $this->recorder->recordSignal(
            $result['event_type'],
            $result['data'],
        );
    }

    private function resolveMapper(object $event): ?MapCommerceEventToSignalInterface
    {
        $eventClass = $event::class;

        if (isset($this->mapperCache[$eventClass])) {
            return $this->mapperCache[$eventClass];
        }

        foreach ($this->container->tagged('signals.event_mappers') as $mapper) {
            if ($mapper instanceof MapCommerceEventToSignalInterface) {
                $handled = method_exists($mapper, 'handledEvents')
                    ? $mapper->handledEvents()
                    : [$mapper->handles()];

                foreach ($handled as $handledEvent) {
                    if ($eventClass === $handledEvent || is_a($eventClass, $handledEvent, true)) {
                        $this->mapperCache[$eventClass] = $mapper;

                        return $mapper;
                    }
                }
            }
        }

        $this->mapperCache[$eventClass] = null;

        return null;
    }
}
