<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services\Recorders;

use AIArmada\Signals\Models\SignalEvent;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AffiliateNetworkSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordOfferCreated(object $offer): ?SignalEvent
    {
        return $this->record($offer, 'offer.created', 'affiliate_network');
    }

    public function recordOfferUpdated(object $offer): ?SignalEvent
    {
        return $this->record($offer, 'offer.updated', 'affiliate_network');
    }

    public function recordApplicationSubmitted(object $application): ?SignalEvent
    {
        return $this->record($application, 'application.submitted', 'affiliate_network');
    }

    public function recordApplicationApproved(object $application): ?SignalEvent
    {
        return $this->record($application, 'application.approved', 'affiliate_network');
    }

    public function recordNetworkConversion(object $link, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->record($link, 'network.conversion.recorded', 'affiliate_network');
    }

    private function record(object $subject, string $eventName, string $category): ?SignalEvent
    {
        if (! $subject instanceof Model) {
            throw new InvalidArgumentException(sprintf('Trusted affiliate-network source [%s] must be an Eloquent model.', $subject::class));
        }

        $ownerType = $this->support->stringValue($subject->getAttribute('owner_type'))
            ?? $this->support->stringValue($subject->getAttribute('ownerType'));
        $ownerId = $this->support->stringValue($subject->getAttribute('owner_id'))
            ?? $this->support->stringValue($subject->getAttribute('ownerId'));
        $trackedProperty = $this->support->resolveTrackedPropertyForOwnerReference($ownerType, $ownerId, null, 'affiliate_network');

        if ($trackedProperty === null) {
            return null;
        }

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => $category,
            'occurred_at' => now()->toAtomString(),
            'revenue_minor' => 0,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'subject_id' => $this->support->stringValue($subject->getKey()),
                'status' => $this->support->normalizeStateValue($subject->getAttribute('status')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
