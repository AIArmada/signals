<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\Signals\Contracts\AlertTransport;
use AIArmada\Signals\Data\AlertDeliveryMessage;
use AIArmada\Signals\Features\Alerts\Exceptions\AlertLogMissing;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertDelivery;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Queue\MaxAttemptsExceededException;
use RuntimeException;
use Throwable;

final readonly class ProcessAlertDelivery
{
    public function __construct(
        private AlertTransport $transport,
        private SignalAlertDispatcher $dispatcher,
    ) {}

    public function run(string $deliveryId): ?int
    {
        ['delivery' => $delivery, 'retry_after' => $retryAfter] = $this->claim($deliveryId);

        if (! $delivery instanceof SignalAlertDelivery) {
            return $retryAfter;
        }

        $claimedAttempt = $delivery->attempt_count;

        try {
            $status = $this->deliver($delivery);
            $this->finalizeAttempt($deliveryId, $claimedAttempt, $status, null);
        } catch (AlertLogMissing) {
            $this->deleteOrphan($deliveryId, $claimedAttempt);
        } catch (Throwable $exception) {
            $this->finalizeAttempt($deliveryId, $claimedAttempt, null, $exception);

            throw new RuntimeException('Signals alert delivery failed.', previous: $exception);
        }

        return null;
    }

    public function fail(string $deliveryId, Throwable $exception): void
    {
        $this->connection()->transaction(function () use ($deliveryId, $exception): void {
            $delivery = SignalAlertDelivery::query()->lockForUpdate()->find($deliveryId);

            if (! $delivery instanceof SignalAlertDelivery || in_array($delivery->status, ['sent', 'dead'], true)) {
                return;
            }

            if (! $this->parentExists($delivery)) {
                $delivery->delete();

                return;
            }

            $now = CarbonImmutable::now();

            if ($exception instanceof MaxAttemptsExceededException && $this->leaseRetryDelay($delivery, $now) !== null) {
                return;
            }

            $errorCode = $this->safeErrorCode($exception);

            $delivery->forceFill([
                'status' => 'dead',
                'dead_at' => $now,
                'leased_at' => null,
                'last_error_code' => $errorCode === 'transport_error' ? ($delivery->last_error_code ?? $errorCode) : $errorCode,
            ])->save();
            $this->refreshLog($delivery);
        });
    }

    /**
     * Finalize a transport outcome under a fresh row lock.
     *
     * The network send runs outside any transaction; only this narrow
     * reread-and-write is locked. Terminal sent/dead rows win, and a stale
     * completion never regresses a newer claim (identified by attempt_count).
     */
    private function finalizeAttempt(string $deliveryId, int $claimedAttempt, ?int $status, ?Throwable $error): void
    {
        $this->connection()->transaction(function () use ($deliveryId, $claimedAttempt, $status, $error): void {
            $delivery = SignalAlertDelivery::query()->lockForUpdate()->find($deliveryId);

            if (! $delivery instanceof SignalAlertDelivery) {
                return;
            }

            if (in_array($delivery->status, ['sent', 'dead'], true)) {
                return;
            }

            if ($delivery->attempt_count !== $claimedAttempt) {
                return;
            }

            if (! $this->parentExists($delivery)) {
                $delivery->delete();

                return;
            }

            if ($error instanceof Throwable) {
                $delivery->forceFill([
                    'status' => 'failed',
                    'leased_at' => null,
                    'last_error_code' => $this->safeErrorCode($error),
                ])->save();
            } else {
                $delivery->forceFill([
                    'status' => 'sent',
                    'sent_at' => $delivery->sent_at ?? CarbonImmutable::now(),
                    'leased_at' => null,
                    'response_status' => $status,
                    'last_error_code' => null,
                ])->save();
            }

            $this->refreshLog($delivery);
        });
    }

    /**
     * @return array{delivery: SignalAlertDelivery|null, retry_after: int|null}
     */
    private function claim(string $deliveryId): array
    {
        return $this->connection()->transaction(function () use ($deliveryId): array {
            $delivery = SignalAlertDelivery::query()->lockForUpdate()->find($deliveryId);

            if (! $delivery instanceof SignalAlertDelivery || in_array($delivery->status, ['sent', 'dead'], true)) {
                return ['delivery' => null, 'retry_after' => null];
            }

            if (! $this->parentExists($delivery)) {
                $delivery->delete();

                return ['delivery' => null, 'retry_after' => null];
            }

            $now = CarbonImmutable::now();
            $retryAfter = $this->leaseRetryDelay($delivery, $now);

            if ($retryAfter !== null) {
                return ['delivery' => null, 'retry_after' => $retryAfter];
            }

            if ($delivery->attempt_count >= $delivery->max_attempts) {
                $delivery->forceFill(['status' => 'dead', 'dead_at' => $now, 'leased_at' => null])->save();
                $this->refreshLog($delivery);

                return ['delivery' => null, 'retry_after' => null];
            }

            $delivery->forceFill([
                'status' => 'processing',
                'attempt_count' => $delivery->attempt_count + 1,
                'leased_at' => $now,
                'last_attempt_at' => $now,
            ])->save();

            return ['delivery' => $delivery, 'retry_after' => null];
        });
    }

    private function deleteOrphan(string $deliveryId, int $claimedAttempt): void
    {
        $this->connection()->transaction(function () use ($deliveryId, $claimedAttempt): void {
            $delivery = SignalAlertDelivery::query()->lockForUpdate()->find($deliveryId);

            if (! $delivery instanceof SignalAlertDelivery || $delivery->attempt_count !== $claimedAttempt) {
                return;
            }

            $delivery->delete();
        });
    }

    private function deliver(SignalAlertDelivery $delivery): ?int
    {
        $log = $delivery->alertLog()->first();

        if (! $log instanceof SignalAlertLog) {
            throw new AlertLogMissing('Signal alert log no longer exists.');
        }

        return $this->transport->send($delivery->channel, $delivery->destination ?? [], new AlertDeliveryMessage(
            ruleId: $log->signal_alert_rule_id,
            logId: $log->id,
            title: $log->title,
            severity: $log->severity,
            metricKey: $log->metric_key,
            message: $log->message,
            context: $log->context,
        ));
    }

    private function safeErrorCode(Throwable $exception): string
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $message = mb_strtolower($current->getMessage());
            $code = match (true) {
                preg_match('/\bhttp_[1-5][0-9]{2}\b/', $message, $matches) === 1 => $matches[0],
                str_contains($message, 'invalid_destination') => 'invalid_destination',
                str_contains($message, 'unsupported_channel') => 'unsupported_channel',
                str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
                str_contains($message, 'resolve'), str_contains($message, 'public') => 'unsafe_destination',
                default => null,
            };

            if ($code !== null) {
                return $code;
            }
        }

        return 'transport_error';
    }

    private function refreshLog(SignalAlertDelivery $delivery): void
    {
        $log = SignalAlertLog::query()->find($delivery->signal_alert_log_id);

        if ($log instanceof SignalAlertLog) {
            $this->dispatcher->refreshLogSummary($log);
        }
    }

    private function parentExists(SignalAlertDelivery $delivery): bool
    {
        return SignalAlertLog::query()->whereKey($delivery->signal_alert_log_id)->exists();
    }

    private function leaseRetryDelay(SignalAlertDelivery $delivery, CarbonImmutable $now): ?int
    {
        if ($delivery->status !== 'processing' || $delivery->leased_at === null) {
            return null;
        }

        $leaseSeconds = max(30, (int) config('signals.features.alerts.delivery.lease_seconds', 120));
        $expiry = $delivery->leased_at->addSeconds($leaseSeconds);

        return $expiry->isAfter($now) ? (int) ceil($now->diffInSeconds($expiry)) : null;
    }

    private function connection(): ConnectionInterface
    {
        return (new SignalAlertDelivery)->getConnection();
    }
}
