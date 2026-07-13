<?php

declare(strict_types=1);

namespace AIArmada\Signals\Data;

use Illuminate\Http\Request;

final readonly class TrustedSignalOutcomeData
{
    /** @param array<string, mixed>|null $properties */
    public function __construct(
        public string $writeKey,
        public string $eventName,
        public string $eventCategory,
        public string $idempotencyKey,
        public string $transactionId,
        public int $revenueMinor,
        public string $currency,
        public ?string $externalId,
        public ?string $anonymousId,
        public ?string $occurredAt,
        public ?array $properties,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string', 'max:255'],
            'event_name' => ['required', 'string', 'max:255'],
            'event_category' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'transaction_id' => ['required', 'string', 'max:255'],
            'revenue_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'anonymous_id' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'properties' => ['nullable', 'array'],
        ]);

        return new self(
            writeKey: (string) $payload['write_key'],
            eventName: (string) $payload['event_name'],
            eventCategory: (string) $payload['event_category'],
            idempotencyKey: (string) $payload['idempotency_key'],
            transactionId: (string) $payload['transaction_id'],
            revenueMinor: (int) $payload['revenue_minor'],
            currency: mb_strtoupper((string) $payload['currency']),
            externalId: self::nullableString($payload['external_id'] ?? null),
            anonymousId: self::nullableString($payload['anonymous_id'] ?? null),
            occurredAt: self::nullableString($payload['occurred_at'] ?? null),
            properties: is_array($payload['properties'] ?? null) ? $payload['properties'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toEventPayload(): array
    {
        $properties = $this->properties ?? [];
        $properties['transaction_id'] = $this->transactionId;

        return [
            'event_name' => $this->eventName,
            'event_category' => $this->eventCategory,
            'idempotency_key' => $this->idempotencyKey,
            'source_event_id' => $this->transactionId,
            'revenue_minor' => $this->revenueMinor,
            'currency' => $this->currency,
            'external_id' => $this->externalId,
            'anonymous_id' => $this->anonymousId,
            'occurred_at' => $this->occurredAt,
            'properties' => $properties,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
