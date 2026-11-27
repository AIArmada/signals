<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions\Recorders;

use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

final class AffiliateNetworkSignalRecorder
{
    public function __construct(private readonly SignalRecorderSupport $support) {}

    public function recordOfferCreated(object $subject, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->record($subject, 'offer.created', 'affiliate_network', $revenueMinor);
    }

    public function recordOfferUpdated(object $subject, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->record($subject, 'offer.updated', 'affiliate_network', $revenueMinor);
    }

    public function recordApplicationSubmitted(object $subject, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->record($subject, 'application.submitted', 'affiliate_network', $revenueMinor);
    }

    public function recordApplicationApproved(object $subject, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->record($subject, 'application.approved', 'affiliate_network', $revenueMinor);
    }

    public function recordNetworkConversion(object $subject, int $revenueMinor = 0): ?SignalEvent
    {
        return $this->record($subject, 'network.conversion.recorded', 'affiliate_network', $revenueMinor);
    }

    private function record(object $subject, string $eventName, string $category, int $revenueMinor): ?SignalEvent
    {
        if ($subject instanceof Model) {
            return $this->recordModel($subject, $eventName, $category, $revenueMinor);
        }

        return $this->recordEvent($subject, $eventName, $category, $revenueMinor);
    }

    private function recordModel(Model $subject, string $eventName, string $category, int $revenueMinor): ?SignalEvent
    {
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
            'revenue_minor' => $revenueMinor,
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'subject_id' => $this->support->stringValue($subject->getKey()),
                'status' => $this->statusValue($subject->getAttribute('status')),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function recordEvent(object $event, string $eventName, string $category, int $revenueMinor): ?SignalEvent
    {
        $leg = $this->property($event, 'leg');

        $ownerType = $this->support->stringValue($this->property($event, 'ownerType'));
        $ownerId = $this->support->stringValue($this->property($event, 'ownerId'));
        $trackedProperty = $this->support->resolveTrackedPropertyForOwnerReference($ownerType, $ownerId, null, 'affiliate_network');

        if ($trackedProperty === null) {
            return null;
        }

        $subjectId = $this->support->stringValue($this->property($event, 'offerId'))
            ?? $this->support->stringValue($this->property($event, 'applicationId'))
            ?? $this->support->stringValue($this->property($leg, 'id'));

        $status = $this->statusValue($this->property($event, 'status'))
            ?? $this->statusValue($this->property($leg, 'status'));

        $counterRevenue = $this->support->optionalPublicInt($event, 'counterRevenueMinor') ?? $revenueMinor;
        $currency = $this->support->stringValue($this->property($event, 'currency'))
            ?? (string) config('signals.defaults.currency', 'MYR');

        return $this->support->ingest($trackedProperty, [
            'event_name' => $eventName,
            'event_category' => $category,
            'occurred_at' => now()->toAtomString(),
            'revenue_minor' => $counterRevenue,
            'currency' => $currency,
            'properties' => array_filter([
                'subject_id' => $subjectId,
                'status' => $status,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function property(object $subject, string $property): mixed
    {
        return property_exists($subject, $property) ? $subject->{$property} : null;
    }

    private function statusValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $this->support->normalizeStateValue($value);
    }
}
