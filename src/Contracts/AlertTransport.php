<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

use AIArmada\Signals\Data\AlertDeliveryMessage;

interface AlertTransport
{
    /** @param array<string, mixed> $destination */
    public function send(string $channel, array $destination, AlertDeliveryMessage $message): ?int;
}
