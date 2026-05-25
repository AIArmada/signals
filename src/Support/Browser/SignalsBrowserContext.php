<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support\Browser;

final readonly class SignalsBrowserContext
{
    public function __construct(
        public string $visitorId,
        public string $sessionId,
        public ?string $sessionStartedAt = null,
        public bool $visitorWasGenerated = false,
        public bool $sessionWasGenerated = false,
    ) {}
}
