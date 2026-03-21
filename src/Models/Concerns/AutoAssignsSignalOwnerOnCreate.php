<?php

declare(strict_types=1);

namespace AIArmada\Signals\Models\Concerns;

use AIArmada\CommerceSupport\Support\OwnerContext;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait AutoAssignsSignalOwnerOnCreate
{
    protected static function bootAutoAssignsSignalOwnerOnCreate(): void
    {
        static::creating(function (Model $model): void {
            if (! self::signalOwnerScopingEnabled()) {
                return;
            }

            self::assertSignalOwnerColumnsAreValid($model);
            self::assignSignalOwnerFromContext($model);
            self::assertSignalOwnerColumnsAreValid($model);
            self::assertSignalOwnerMatchesResolvedContext($model);
        });

        static::saving(function (Model $model): void {
            if (! self::signalOwnerScopingEnabled()) {
                return;
            }

            self::assertSignalOwnerColumnsAreValid($model);

            if (! $model->exists) {
                return;
            }

            self::assertSignalOwnerIsImmutable($model);
            self::assertSignalOwnerMatchesResolvedContext($model);
        });
    }

    private static function signalOwnerScopingEnabled(): bool
    {
        return (bool) config('signals.features.owner.enabled', true);
    }

    private static function assertSignalOwnerColumnsAreValid(Model $model): void
    {
        $ownerType = $model->getAttribute('owner_type');
        $ownerId = $model->getAttribute('owner_id');
        $hasOwnerType = $ownerType !== null;
        $hasOwnerId = $ownerId !== null;

        if ($hasOwnerType !== $hasOwnerId) {
            throw new InvalidArgumentException('Invalid owner columns: owner_type and owner_id must be both set or both null.');
        }
    }

    private static function assignSignalOwnerFromContext(Model $model): void
    {
        if (! (bool) config('signals.features.owner.auto_assign_on_create', true)) {
            return;
        }

        $owner = OwnerContext::resolve();
        $hasOwnerType = $model->getAttribute('owner_type') !== null;

        if ($owner === null || $hasOwnerType || ! method_exists($model, 'assignOwner')) {
            return;
        }

        $model->assignOwner($owner);
    }

    private static function assertSignalOwnerMatchesResolvedContext(Model $model): void
    {
        $owner = OwnerContext::resolve();

        if ($owner === null || $model->getAttribute('owner_type') === null || ! method_exists($model, 'belongsToOwner')) {
            return;
        }

        if (! $model->belongsToOwner($owner)) {
            throw new InvalidArgumentException('Cross-tenant write blocked: model owner does not match the current owner context.');
        }
    }

    private static function assertSignalOwnerIsImmutable(Model $model): void
    {
        $originalOwnerType = $model->getOriginal('owner_type');
        $originalOwnerId = $model->getOriginal('owner_id');
        $currentOwnerType = $model->getAttribute('owner_type');
        $currentOwnerId = $model->getAttribute('owner_id');

        if ($originalOwnerType === $currentOwnerType && $originalOwnerId === $currentOwnerId) {
            return;
        }

        throw new InvalidArgumentException('Cross-tenant write blocked: owner columns cannot be reassigned after creation.');
    }
}
