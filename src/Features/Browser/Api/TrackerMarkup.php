<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Browser\Api;

interface TrackerMarkup
{
    /** @param array<string, mixed> $overrides */
    public function render(array $overrides = []): string;
}
