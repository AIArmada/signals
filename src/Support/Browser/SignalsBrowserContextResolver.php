<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support\Browser;

use AIArmada\Signals\Contracts\BrowserContextResolverInterface;
use Illuminate\Http\Request;

/**
 * Resolves browser-side tracking context (write key, identity, session)
 * from the current HTTP request. Acts as the single entry point for
 * extracting tracking parameters from cookies, headers, and query strings.
 */
final class SignalsBrowserContextResolver implements BrowserContextResolverInterface
{
    public function __construct(
        private readonly Request $request,
    ) {}

    public function resolve(): ?array
    {
        $writeKey = $this->getWriteKey();

        if ($writeKey === null) {
            return null;
        }

        return [
            'write_key' => $writeKey,
            'external_id' => $this->request->cookie('external_id'),
            'anonymous_id' => $this->request->cookie('anonymous_id'),
            'email' => $this->request->cookie('email'),
            'session_identifier' => $this->getSessionIdentifier(),
        ];
    }

    public function getWriteKey(): ?string
    {
        $writeKey = $this->request->cookie(config('signals.cookie.write_key_name', 'swk'))
            ?? $this->request->header('X-Signals-Write-Key');

        return is_string($writeKey) && $writeKey !== '' ? $writeKey : null;
    }

    public function getSessionIdentifier(): ?string
    {
        $sessionKey = config('signals.cookie.session_key', 'ssid');

        $session = $this->request->cookie($sessionKey)
            ?? $this->request->header('X-Signals-Session-Id');

        return is_string($session) && $session !== '' ? $session : null;
    }
}
