<?php

declare(strict_types=1);

namespace AIArmada\Signals\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\SignalsIngestionRequestValidator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final class IdentifySignalIdentity
{
    use AsAction;

    public function __construct(private readonly SignalsIngestionRequestValidator $requestValidator) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, array $payload): SignalIdentity
    {
        $identity = $this->resolveIdentity($trackedProperty, $payload);
        $seenAt = $this->resolveSeenAt($payload);

        $traits = is_array($payload['traits'] ?? null) ? $payload['traits'] : null;

        [$authUserType, $authUserId] = $this->resolveAuthUser($payload);

        $identity->fill([
            'email' => $payload['email'] ?? $identity->email,
            'external_id' => $payload['external_id'] ?? $identity->external_id,
            'anonymous_id' => $payload['anonymous_id'] ?? $identity->anonymous_id,
            'traits' => $traits ?? $identity->traits,
            'first_seen_at' => $identity->first_seen_at ?? $seenAt,
            'last_seen_at' => $seenAt,
            'auth_user_type' => $authUserType ?? $identity->auth_user_type,
            'auth_user_id' => $authUserId ?? $identity->auth_user_id,
        ]);

        if (! $identity->exists) {
            $identity->tracked_property_id = $trackedProperty->id;
        }

        $this->syncOwnerFromProperty($identity, $trackedProperty);
        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        OwnerContext::withOwner($owner, static fn (): bool => $identity->save());

        return $identity;
    }

    public function asController(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'write_key' => ['required', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'anonymous_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'traits' => ['nullable', 'array'],
            'auth_user_type' => ['nullable', 'string', 'max:255'],
            'auth_user_id' => ['nullable', 'string', 'max:255'],
            'seen_at' => ['nullable', 'date'],
        ]);

        if (($payload['external_id'] ?? null) === null && ($payload['anonymous_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'external_id' => 'Either external_id or anonymous_id is required.',
            ]);
        }

        $trackedProperty = $this->requestValidator->resolveTrackedProperty($request, (string) $payload['write_key']);
        $identity = $this->handle($trackedProperty, $payload);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'identity_id' => $identity->id,
                'tracked_property_id' => $trackedProperty->id,
            ],
        ], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveIdentity(TrackedProperty $trackedProperty, array $payload): SignalIdentity
    {
        $query = SignalIdentity::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', $trackedProperty->id);

        if (($payload['external_id'] ?? null) !== null) {
            $existing = (clone $query)->where('external_id', $payload['external_id'])->first();

            if ($existing instanceof SignalIdentity) {
                return $existing;
            }
        }

        if (($payload['anonymous_id'] ?? null) !== null) {
            $existing = (clone $query)->where('anonymous_id', $payload['anonymous_id'])->first();

            if ($existing instanceof SignalIdentity) {
                return $existing;
            }
        }

        return new SignalIdentity;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSeenAt(array $payload): CarbonImmutable
    {
        $seenAt = $payload['seen_at'] ?? null;

        return is_string($seenAt) ? CarbonImmutable::parse($seenAt) : CarbonImmutable::now();
    }

    private function syncOwnerFromProperty(SignalIdentity $identity, TrackedProperty $trackedProperty): void
    {
        if (! $trackedProperty->hasOwner()) {
            return;
        }

        $identity->owner_type = $trackedProperty->owner_type;
        $identity->owner_id = $trackedProperty->owner_id;
    }

    /**
     * Resolve auth user type and ID from the payload or — when auth_tracking is
     * enabled — from the currently authenticated Laravel user.
     *
     * @param  array<string, mixed>  $payload
     * @return array{string|null, string|null}
     */
    private function resolveAuthUser(array $payload): array
    {
        // Explicit payload values take priority
        if (($payload['auth_user_type'] ?? null) !== null && ($payload['auth_user_id'] ?? null) !== null) {
            return [(string) $payload['auth_user_type'], (string) $payload['auth_user_id']];
        }

        if (! config('signals.features.auth_tracking.enabled', false)) {
            return [null, null];
        }

        if (! auth()->check()) {
            return [null, null];
        }

        /** @var Authenticatable $user */
        $user = auth()->user();

        $morphMap = Relation::morphMap();
        $userClass = get_class($user);
        $userType = array_search($userClass, $morphMap, true);

        if ($userType === false) {
            $userType = $userClass;
        }

        return [(string) $userType, (string) $user->getAuthIdentifier()];
    }
}
