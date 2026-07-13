<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\Signals\Data\TrustedSignalOutcomeData;
use AIArmada\Signals\Services\SignalsIngestionRequestValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final class IngestTrustedSignalOutcome
{
    use AsAction;

    public function __construct(
        private readonly IngestSignalEvent $ingestSignalEvent,
        private readonly SignalsIngestionRequestValidator $requestValidator,
    ) {}

    public function asController(Request $request): JsonResponse
    {
        $this->requestValidator->assertTrustedPayloadWithinLimits($request);
        $data = TrustedSignalOutcomeData::fromRequest($request);
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
