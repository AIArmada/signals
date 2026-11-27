<?php

declare(strict_types=1);

namespace AIArmada\Signals\Drivers;

use AIArmada\CommerceSupport\Drivers\Http\PinnedHttpClient;
use AIArmada\CommerceSupport\Support\PublicHttpUrlGuard;
use AIArmada\Signals\Contracts\AlertTransport;
use AIArmada\Signals\Data\AlertDeliveryMessage;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final readonly class DefaultAlertTransport implements AlertTransport
{
    public function __construct(
        private PublicHttpUrlGuard $urlGuard,
        private PinnedHttpClient $http,
    ) {}

    public function send(string $channel, array $destination, AlertDeliveryMessage $message): ?int
    {
        return match ($channel) {
            'email' => $this->sendEmail($destination, $message),
            'webhook' => $this->sendWebhook($destination, $message),
            'slack' => $this->sendSlack($destination, $message),
            default => throw new RuntimeException('unsupported_channel'),
        };
    }

    /** @param array<string, mixed> $destination */
    private function sendEmail(array $destination, AlertDeliveryMessage $log): ?int
    {
        $to = $destination['to'] ?? null;

        if (! is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('invalid_destination');
        }

        Mail::raw((string) $log->message, static function ($mail) use ($to, $log): void {
            $mail->to($to)->subject('[Signals] ' . $log->title);
        });

        return null;
    }

    /** @param array<string, mixed> $destination */
    private function sendWebhook(array $destination, AlertDeliveryMessage $log): int
    {
        $url = $destination['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('invalid_destination');
        }

        return $this->postJson($url, [
            'alert_rule_id' => $log->ruleId,
            'alert_log_id' => $log->logId,
            'name' => $log->title,
            'severity' => $log->severity,
            'message' => $log->message,
            'context' => $log->context,
        ]);
    }

    /** @param array<string, mixed> $destination */
    private function sendSlack(array $destination, AlertDeliveryMessage $log): int
    {
        $url = $destination['webhook_url'] ?? ($destination['url'] ?? null);

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('invalid_destination');
        }

        return $this->postJson($url, [
            'text' => $log->message,
            'attachments' => [[
                'title' => $log->title,
                'color' => $log->severity === 'critical' ? 'danger' : 'warning',
                'fields' => [
                    ['title' => 'Metric', 'value' => $log->metricKey, 'short' => true],
                    ['title' => 'Log', 'value' => $log->logId, 'short' => true],
                ],
            ]],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): int
    {
        $target = $this->urlGuard->validate($url);
        $response = $this->http->send(
            method: 'POST',
            target: $target,
            options: ['json' => $payload],
            headers: ['Accept' => 'application/json'],
            connectTimeout: max(1, (int) config('signals.features.alerts.delivery.connect_timeout_seconds', 3)),
            timeout: max(1, (int) config('signals.features.alerts.delivery.timeout_seconds', 10)),
        );

        if (! $response->successful()) {
            throw new RuntimeException('http_' . $response->status());
        }

        return $response->status();
    }
}
