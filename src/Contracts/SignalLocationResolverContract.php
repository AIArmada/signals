<?php

declare(strict_types=1);

namespace AIArmada\Signals\Contracts;

use AIArmada\Signals\Models\SignalSession;

interface SignalLocationResolverContract
{
    /**
     * Enrich a session with additional location data after reverse geocoding.
     *
     * Consuming applications bind their own resolver to this contract in order
     * to populate custom fields (e.g. district_id, subdistrict_id, timezone zone)
     * without modifying the signals package itself.
     *
     * The resolver MUST be side-effect free regarding the resolved fields it does
     * NOT own (i.e. do not null-out fields already set by the pipeline).
     *
     * @param  SignalSession  $session  The session being enriched (already persisted)
     * @param  array<string, mixed>  $rawPayload  The raw reverse-geocode response keyed by provider
     */
    public function resolve(SignalSession $session, array $rawPayload): void;
}
