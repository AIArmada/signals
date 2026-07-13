<?php

declare(strict_types=1);

namespace AIArmada\Signals\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class VerifyTrustedSignalSignature
{
    public function __construct(private readonly RateLimiter $rateLimiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('signals.ingestion.trusted.secret');

        if (! is_string($secret) || trim($secret) === '') {
            throw new HttpException(503, 'Trusted Signals ingestion is not configured.');
        }

        $timestampHeader = $request->headers->get('X-Signals-Timestamp');
        $signatureHeader = $request->headers->get('X-Signals-Signature');

        if (! is_string($timestampHeader) || preg_match('/^\d{10}$/', $timestampHeader) !== 1) {
            throw new HttpException(401, 'A valid Signals timestamp is required.');
        }

        if (! is_string($signatureHeader) || trim($signatureHeader) === '') {
            throw new HttpException(401, 'A valid Signals signature is required.');
        }

        $timestamp = (int) $timestampHeader;
        $replayWindowSeconds = max(30, (int) config('signals.ingestion.trusted.replay_window_seconds', 300));

        if (abs(time() - $timestamp) > $replayWindowSeconds) {
            throw new HttpException(401, 'The Signals signature timestamp is outside the replay window.');
        }

        $providedSignature = strtolower(trim($signatureHeader));

        if (str_starts_with($providedSignature, 'sha256=')) {
            $providedSignature = substr($providedSignature, 7);
        }

        if (preg_match('/^[a-f0-9]{64}$/', $providedSignature) !== 1) {
            throw new HttpException(401, 'The Signals signature format is invalid.');
        }

        $expectedSignature = hash_hmac('sha256', $timestampHeader . '.' . $request->getContent(), $secret);

        if (! hash_equals($expectedSignature, $providedSignature)) {
            throw new HttpException(401, 'The Signals signature is invalid.');
        }

        $replayKey = 'signals:trusted:signature:' . hash('sha256', $timestampHeader . ':' . $providedSignature);

        if (! $this->rateLimiter->attempt($replayKey, 1, static fn (): bool => true, $replayWindowSeconds)) {
            throw new HttpException(409, 'The trusted Signals request has already been processed.');
        }

        return $next($request);
    }
}
