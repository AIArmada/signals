<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Browser\Queries;

use AIArmada\Signals\Features\Browser\Api\BrowserPageProperties;
use AIArmada\Signals\Features\Browser\Api\BrowserPagePropertyContributor;

final class BrowserPagePropertyRegistry implements BrowserPageProperties
{
    /** @var array<class-string, BrowserPagePropertyContributor> */
    private array $contributors = [];

    public function add(BrowserPagePropertyContributor $contributor): void
    {
        $this->contributors[$contributor::class] = $contributor;
    }

    /** @return array<string, mixed> */
    public function currentPageProperties(): array
    {
        $properties = [];

        foreach ($this->contributors as $contributor) {
            $properties = array_merge($properties, $contributor->currentPageProperties());
        }

        return $properties;
    }
}
