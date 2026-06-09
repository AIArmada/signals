<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

interface MapCommerceEventToSignalInterface
{
    /**
     * @return array{event_type: string, data: array<string, mixed>}|null
     */
    public function map(object $event): ?array;

    /**
     * Get the FQCN of the event class this mapper handles.
     *
     * @return class-string
     */
    public function handles(): string;
}
