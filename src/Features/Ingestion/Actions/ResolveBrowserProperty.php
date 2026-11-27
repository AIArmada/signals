<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Ingestion\Api\BrowserProperty;
use AIArmada\Signals\Features\Ingestion\Api\BrowserPropertyResolver;
use Illuminate\Database\Eloquent\Model;

final readonly class ResolveBrowserProperty implements BrowserPropertyResolver
{
    public function __construct(private ResolveTrackedProperty $properties) {}

    public function resolve(): ?BrowserProperty
    {
        $owner = OwnerContext::resolve();

        if ((bool) config('signals.owner.enabled', false) && ! $owner instanceof Model && ! OwnerContext::isExplicitGlobal()) {
            return null;
        }

        $property = $this->properties->resolveForOwner($owner, integration: 'browser');

        return $property === null ? null : new BrowserProperty((string) $property->getKey(), $property->write_key);
    }
}
