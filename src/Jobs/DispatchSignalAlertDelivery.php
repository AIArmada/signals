<?php

declare(strict_types=1);

namespace AIArmada\Signals\Jobs;

use AIArmada\CommerceSupport\Contracts\OwnerScopedJob;
use AIArmada\CommerceSupport\Http\PinnedHttpClient;
use AIArmada\CommerceSupport\Support\OwnerJobContext;
use AIArmada\CommerceSupport\Support\PublicHttpUrlGuard;
use AIArmada\CommerceSupport\Traits\OwnerContextJob;
use AIArmada\Signals\Models\SignalAlertDelivery;
use AIArmada\Signals\Models\SignalAlertLog;
use AIArmada\Signals\Services\SignalAlertDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class DispatchSignalAlertDelivery implements OwnerScopedJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use OwnerContextJob;
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public string $deliveryId,
        public ?string $ownerType,
        public string | int | null $ownerId,
        public bool $ownerIsGlobal = false,
    ) {
        $this->tries = max(1, (int) config('signals.features.alerts.delivery.max_attempts', 5));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $configured = config('signals.features.alerts.delivery.backoff_seconds', [10, 30, 120, 300]);

        return is_array($configured)
            ? array_values(array_map('intval', array_filter($configured, 'is_numeric')))
            : [10, 30, 120, 300];
    }

    public function ownerContext(): OwnerJobContext
    {
        return new OwnerJobContext($this->ownerType, $this->ownerId, $this->ownerIsGlobal);
    }

    protected function performJob(): void
    {
        $delivery = $this->claim();

        if (! $delivery instanceof SignalAlertDelivery) {
            return;
        }

        try {
            $status = $this->deliver($delivery);
            $delivery->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'leased_at' => null,
                'response_status' => $status,
                'last_error_code' => null,
            ])->save();
            $this->refreshLog($delivery);
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => 'failed',
                'leased_at' => null,
                'last_error_code' => $this->safeErrorCode($exception),
            ])->save();
            $this->refreshLog($delivery);

            throw new RuntimeException('Signals alert delivery failed.', previous: $exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        $delivery = SignalAlertDelivery::query()->withoutOwnerScope()->find($this->deliveryId);

        if (! $delivery instanceof SignalAlertDelivery || $delivery->status === 'sent') {
            return;
        }

        $delivery->forceFill([
            'status' => 'dead',
            'dead_at' => now(),
            'leased_at' => null,
            'last_error_code' => $this->safeErrorCode($exception),
        ])->save();
        $this->refreshLog($delivery);
    }

    private function claim(): ?SignalAlertDelivery
    {
        return DB::transaction(function (): ?SignalAlertDelivery {
            $delivery = SignalAlertDelivery::query()->lockForUpdate()->find($this->deliveryId);

            if (! $delivery instanceof SignalAlertDelivery || in_array($delivery->status, ['sent', 'dead'], true)) {
                return null;
            }

            $leaseSeconds = max(30, (int) config('signals.features.alerts.delivery.lease_seconds', 120));

            if ($delivery->status === 'processing' && $delivery->leased_at?->isAfter(now()->subSeconds($leaseSeconds))) {
                return null;
            }

            if ($delivery->attempt_count >= $delivery->max_attempts) {
                $delivery->forceFill(['status' => 'dead', 'dead_at' => now(), 'leased_at' => null])->save();

                return null;
            }

            $delivery->forceFill([
                'status' => 'processing',
                'attempt_count' => $delivery->attempt_count + 1,
                'leased_at' => now(),
                'last_attempt_at' => now(),
            ])->save();

            return $delivery;
        });
    }

    private function deliver(SignalAlertDelivery $delivery): ?int
    {
        $destination = is_array($delivery->destination) ? $delivery->destination : [];
        $log = $delivery->alertLog()->firstOrFail();

        return match ($delivery->channel) {
            'email' => $this->sendEmail($destination, $log),
            'webhook' => $this->sendWebhook($destination, $log),
            'slack' => $this->sendSlack($destination, $log),
            default => throw new RuntimeException('unsupported_channel'),
        };
    }

    /** @param array<string, mixed> $destination */
    private function sendEmail(array $destination, SignalAlertLog $log): ?int
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
    private function sendWebhook(array $destination, SignalAlertLog $log): int
    {
        $url = $destination['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('invalid_destination');
        }

        return $this->postJson($url, [
            'alert_rule_id' => $log->signal_alert_rule_id,
            'alert_log_id' => $log->id,
            'name' => $log->title,
            'severity' => $log->severity,
            'message' => $log->message,
            'context' => $log->context,
        ]);
    }

    /** @param array<string, mixed> $destination */
    private function sendSlack(array $destination, SignalAlertLog $log): int
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
                    ['title' => 'Metric', 'value' => $log->metric_key, 'short' => true],
                    ['title' => 'Log', 'value' => $log->id, 'short' => true],
                ],
            ]],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): int
    {
        $target = app(PublicHttpUrlGuard::class)->validate($url);
        $response = app(PinnedHttpClient::class)->send(
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

    private function safeErrorCode(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'http_') => mb_substr($message, 0, 64),
            str_contains($message, 'invalid_destination') => 'invalid_destination',
            str_contains($message, 'unsupported_channel') => 'unsupported_channel',
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'resolve'), str_contains($message, 'public') => 'unsafe_destination',
            default => 'transport_error',
        };
    }

    private function refreshLog(SignalAlertDelivery $delivery): void
    {
        $log = SignalAlertLog::query()->find($delivery->signal_alert_log_id);

        if ($log instanceof SignalAlertLog) {
            app(SignalAlertDispatcher::class)->refreshLogSummary($log);
        }
    }
}
