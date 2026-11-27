<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Alerts\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Alerts\Api\AlertReadState;
use AIArmada\Signals\Features\Alerts\Models\SignalAlertLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

final readonly class SetAlertReadState implements AlertReadState
{
    public function set(array $alertIds, bool $read): void
    {
        foreach ($alertIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new AuthorizationException('Alert is not accessible.');
            }
        }

        $ids = array_values(array_unique($alertIds));

        if ($ids === []) {
            return;
        }

        $model = new SignalAlertLog;

        $model->getConnection()->transaction(function () use ($ids, $read): void {
            $query = SignalAlertLog::query();

            if (SignalAlertLog::ownerScopingEnabled()) {
                $owner = OwnerContext::resolve();
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Owner context is required to update alert read state.');
                $query->forOwner($owner, includeGlobal: false);
            }

            $query->whereKey($ids);

            $locked = (clone $query)->orderBy('id')->lockForUpdate()->pluck('id');

            if ($locked->count() !== count($ids)) {
                throw new AuthorizationException('Alert is not accessible.');
            }

            if ($read) {
                $query->whereNull('read_at')->update(['read_at' => CarbonImmutable::now()]);
            } else {
                $query->whereNotNull('read_at')->update(['read_at' => null]);
            }
        });
    }
}
