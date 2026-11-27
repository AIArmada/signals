<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

interface BrowserPropertyResolver
{
    public function resolve(): ?BrowserProperty;
}
