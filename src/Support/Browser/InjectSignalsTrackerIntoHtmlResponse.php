<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support\Browser;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InjectSignalsTrackerIntoHtmlResponse
{
    public function __construct(private readonly SignalsTrackerRenderer $trackerRenderer) {}

    public function handle(RequestHandled $event): void
    {
        if (! (bool) config('signals.integrations.browser.enabled', false)) {
            return;
        }

        if (! (bool) config('signals.integrations.browser.auto_inject', true)) {
            return;
        }

        if (! $event->request->isMethod('GET')) {
            return;
        }

        $response = $event->response;

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return;
        }

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return;
        }

        $contentType = mb_strtolower((string) $response->headers->get('Content-Type', ''));

        if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml+xml')) {
            return;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return;
        }

        if (str_contains($content, 'data-signals-tracker=')) {
            return;
        }

        $markup = $this->trackerRenderer->render();

        if ($markup === '') {
            return;
        }

        $response->setContent($this->injectMarkup($content, $markup));
    }

    private function injectMarkup(string $content, string $markup): string
    {
        $bodyPosition = mb_strripos($content, '</body>');

        if ($bodyPosition !== false) {
            return mb_substr($content, 0, $bodyPosition) . $markup . PHP_EOL . mb_substr($content, $bodyPosition);
        }

        $htmlPosition = mb_strripos($content, '</html>');

        if ($htmlPosition !== false) {
            return mb_substr($content, 0, $htmlPosition) . $markup . PHP_EOL . mb_substr($content, $htmlPosition);
        }

        return $content . PHP_EOL . $markup;
    }
}
