<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\EntryPoints\Http;

use AIArmada\Signals\Data\TrustedSignalOutcomeData;
use AIArmada\Signals\Features\Ingestion\Actions\IngestSignalEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class TrustedOutcomeController
{
    public function __construct(
        private IngestSignalEvent $ingestSignalEvent,
        private SignalsIngestionRequestValidator $requestValidator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->requestValidator->assertTrustedPayloadWithinLimits($request);
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
        $data = TrustedSignalOutcomeData::fromPayload($payload);
        $trackedProperty = $this->requestValidator->resolveTrustedProperty($data->writeKey);
        $event = $this->ingestSignalEvent->handle($trackedProperty, $data->toEventPayload(), trusted: true);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'event_id' => $event->id,
                'tracked_property_id' => $trackedProperty->id,
                'idempotency_key' => $data->idempotencyKey,
            ],
        ], 202);
    }
}
