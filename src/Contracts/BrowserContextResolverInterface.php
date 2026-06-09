<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

interface BrowserContextResolverInterface
{
    /**
     * @return array{write_key: string, external_id: string|null, anonymous_id: string|null, email: string|null, session_identifier: string|null}
     */
    public function resolve(): ?array;

    public function getWriteKey(): ?string;

    public function getSessionIdentifier(): ?string;
}
