<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

interface UserAgentParser
{
    /** @return array{device_type: ?string, device_brand: ?string, device_model: ?string, browser: ?string, browser_version: ?string, os: ?string, os_version: ?string, is_bot: bool} */
    public function parse(string $userAgent): array;
}
