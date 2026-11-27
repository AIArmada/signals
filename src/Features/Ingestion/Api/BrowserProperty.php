<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Api;

final readonly class BrowserProperty
{
    public function __construct(
        public string $id,
        public string $writeKey,
    ) {}
}
