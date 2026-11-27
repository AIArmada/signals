<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Browser\Api;

interface BrowserPagePropertyContributor
{
    /** @return array<string, mixed> */
    public function currentPageProperties(): array;
}
