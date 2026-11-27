<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Browser\EntryPoints\Http\Middleware;

use AIArmada\Signals\Support\Http\SignalsBrowserContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BootstrapSignalsBrowserContext
{
    public function __construct(private readonly SignalsBrowserContextManager $browserContextManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('signals.integrations.browser.enabled', false)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $context = $this->browserContextManager->resolveOrCreate($request);

        /** @var Response $response */
        $response = $next($request);
        $this->browserContextManager->queueCookies($response, $context);

        return $response;
    }
}
