<?php

declare(strict_types=1);

namespace AIArmada\Signals\Support\Browser;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Support\ExperimentContextManager;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\TrackedPropertyResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

final class SignalsTrackerRenderer
{
    public function __construct(
        private readonly SignalsBrowserContextManager $browserContextManager,
        private readonly TrackedPropertyResolver $trackedPropertyResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function render(array $overrides = []): string
    {
        if (! (bool) config('signals.integrations.browser.enabled', false)) {
            return '';
        }

        $request = $this->request();

        if (! $request instanceof Request) {
            return '';
        }

        if ($this->browserContextManager->wasTrackerRendered($request)) {
            return '';
        }

        $trackedProperty = $this->resolveTrackedProperty();

        if (! $trackedProperty instanceof TrackedProperty) {
            return '';
        }

        $context = $this->browserContextManager->current($request)
            ?? $this->browserContextManager->resolveOrCreate($request);

        $pageProperties = $this->pageProperties($overrides);
        $attributes = [
            'defer' => true,
            'src' => $this->signalUrl((string) config('signals.http.tracker_script', 'tracker.js')),
            'data-signals-tracker' => '1',
            'data-write-key' => $trackedProperty->write_key,
            'data-endpoint' => $this->signalUrl('collect/pageview'),
            'data-identify-endpoint' => $this->signalUrl('collect/identify'),
            'data-geo-endpoint' => $this->signalUrl('collect/geo'),
            'data-anonymous-id' => $context->visitorId,
            'data-session-id' => $context->sessionId,
            'data-session-started-at' => $context->sessionStartedAt,
            'data-enable-geolocation' => $this->boolOverride(
                $overrides,
                'enableGeolocation',
                (bool) config('signals.integrations.browser.geolocation.enabled', true)
                    && (bool) config('signals.features.geolocation.enabled', true),
            ) ? 'true' : 'false',
            'data-external-id' => $this->externalId($overrides),
            'data-email' => $this->email($overrides),
            'data-page-properties' => $pageProperties === [] ? null : $this->jsonEncode($pageProperties),
        ];

        $this->browserContextManager->markTrackerRendered($request);

        return $this->scriptTag($attributes);
    }

    private function resolveTrackedProperty(): ?TrackedProperty
    {
        $owner = OwnerContext::resolve();

        if ((bool) config('signals.owner.enabled', true) && ! $owner instanceof Model && ! OwnerContext::isExplicitGlobal()) {
            return null;
        }

        return $this->trackedPropertyResolver->resolveForOwner($owner, integration: 'browser');
    }

    private function signalUrl(string $suffix): string
    {
        $prefix = '/' . mb_trim((string) config('signals.http.prefix', 'api/signals'), '/');

        return url($prefix . '/' . mb_ltrim($suffix, '/'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pageProperties(array $overrides): array
    {
        $properties = [];
        $contextProperties = $this->experimentContextProperties();

        if ($contextProperties !== []) {
            $properties = array_merge($properties, $contextProperties);
        }

        $overrideProperties = $overrides['properties'] ?? null;

        if (is_array($overrideProperties)) {
            $properties = array_merge($properties, $overrideProperties);
        }

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    private function experimentContextProperties(): array
    {
        if (! class_exists(ExperimentContextManager::class) || ! app()->bound(ExperimentContextManager::class)) {
            return [];
        }

        $context = app(ExperimentContextManager::class)->current();

        if ($context === null) {
            return [];
        }

        $primaryContext = $context->toArray();

        return array_merge($primaryContext, [
            'experiment_contexts' => [$primaryContext],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function externalId(array $overrides): ?string
    {
        $override = $this->normalizeString($overrides['externalId'] ?? null);

        if ($override !== null) {
            return $override;
        }

        if (! (bool) config('signals.integrations.browser.identify.enabled', true)) {
            return null;
        }

        if (! (bool) config('signals.features.auth_tracking.enabled', false)) {
            return null;
        }

        $user = $this->authenticatedUser();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function email(array $overrides): ?string
    {
        $override = $this->normalizeString($overrides['email'] ?? null);

        if ($override !== null) {
            return $override;
        }

        if (! (bool) config('signals.integrations.browser.identify.enabled', true)) {
            return null;
        }

        if (! (bool) config('signals.features.auth_tracking.enabled', false)) {
            return null;
        }

        $user = $this->authenticatedUser();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        if ($user instanceof MustVerifyEmail) {
            return $this->normalizeString($user->getEmailForVerification());
        }

        if ($user instanceof Model) {
            return $this->normalizeString($user->getAttribute('email'));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function boolOverride(array $overrides, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $overrides)) {
            return $default;
        }

        return filter_var($overrides[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function authenticatedUser(): ?Authenticatable
    {
        try {
            $user = auth()->user();

            return $user instanceof Authenticatable ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function request(): ?Request
    {
        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = mb_trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function scriptTag(array $attributes): string
    {
        $parts = ['<script'];

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $parts[] = ' ' . $name;

                continue;
            }

            $parts[] = sprintf(' %s="%s"', $name, $this->escapeAttribute((string) $value));
        }

        $parts[] = '></script>';

        return implode('', $parts);
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }
}
