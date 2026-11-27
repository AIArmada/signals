<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Ingestion\Api\InteractionRuleAdministration;
use AIArmada\Signals\Features\Ingestion\Models\SignalInteractionRule;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use AIArmada\Signals\Support\DuplicateKeyViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AdministerInteractionRule implements InteractionRuleAdministration
{
    private const int MAX_SCAN_ATTEMPTS = 3;

    public function create(array $data): string
    {
        return (new SignalInteractionRule)->getConnection()->transaction(function () use ($data): string {
            $record = new SignalInteractionRule($this->attributes($data));

            if (SignalInteractionRule::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolveConfigured(SignalInteractionRule::ownerScopeConfig());
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Signals administration requires an owner context.');

                if ($owner !== null) {
                    $record->owner()->associate($owner);
                }
            }

            $record->save();

            return (string) $record->getKey();
        }, attempts: 3);
    }

    public function createScanned(string $propertyId, string $triggerType, string $eventName, ?string $eventCategory, bool $isActive, array $candidates): int
    {
        if (count($candidates) > 500 || ! in_array($triggerType, ['click', 'accordion', 'media', 'youtube'], true) || mb_trim($eventName) === '') {
            throw new InvalidArgumentException('Invalid interaction rule scan.');
        }

        $eventName = mb_trim($eventName);

        if (mb_strlen($eventName, '8bit') > 255) {
            throw new InvalidArgumentException('Interaction rule event name is too long.');
        }

        if ($eventCategory !== null && mb_strlen($eventCategory, '8bit') > 100) {
            throw new InvalidArgumentException('Interaction rule event category is too long.');
        }

        $prepared = $this->prepareCandidates($candidates);

        $connection = (new SignalInteractionRule)->getConnection();
        $persist = function () use ($propertyId, $triggerType, $eventName, $eventCategory, $isActive, $prepared): int {
            $query = SignalInteractionRule::query();
            $properties = TrackedProperty::query();
            $owner = null;

            if (SignalInteractionRule::ownerScopeConfig()->enabled) {
                $owner = OwnerContext::resolveConfigured(SignalInteractionRule::ownerScopeConfig());
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Signals administration requires an owner context.');
                $query = $owner === null ? $query->globalOnly() : $query->forOwner($owner, includeGlobal: false);
                $properties = $owner === null ? $properties->globalOnly() : $properties->forOwner($owner, includeGlobal: false);
            }

            $properties->lockForUpdate()->findOrFail($propertyId);
            $sortOrder = (int) (clone $query)->max('sort_order');
            $existingSlugs = $this->existingSlugs($query, $prepared);
            $nextSuffixes = [];
            $created = 0;

            foreach ($prepared as $candidate) {
                $base = $candidate['base'];
                $slug = $this->nextSlug($base, $existingSlugs, $nextSuffixes);
                $record = new SignalInteractionRule([
                    'tracked_property_id' => $propertyId,
                    'name' => $candidate['name'],
                    'slug' => $slug,
                    'trigger_type' => $triggerType,
                    'event_name' => $eventName,
                    'event_category' => $eventCategory,
                    'selector' => $candidate['selector'],
                    'page_pattern' => $candidate['page_pattern'],
                    'settings' => $candidate['settings'],
                    'sort_order' => ++$sortOrder,
                    'is_active' => $isActive,
                ]);

                if ($owner !== null) {
                    $record->owner()->associate($owner);
                }

                $record->save();
                $created++;
            }

            return $created;
        };

        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction($persist, attempts: 3);
            } catch (QueryException $exception) {
                if ($attempt >= self::MAX_SCAN_ATTEMPTS || ! DuplicateKeyViolation::is($exception)) {
                    throw $exception;
                }
            }
        }
    }

    public function update(string $id, array $data): void
    {
        (new SignalInteractionRule)->getConnection()->transaction(function () use ($id, $data): void {
            $this->lockRecord($id)->update($this->attributes($data));
        }, attempts: 3);
    }

    public function delete(string $id): bool
    {
        return (new SignalInteractionRule)->getConnection()->transaction(
            fn (): bool => $this->lockRecord($id)->delete() === true,
            attempts: 3,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return list<array{base:string,name:string,selector:string|null,page_pattern:string|null,settings:array<string,mixed>}>
     */
    private function prepareCandidates(array $candidates): array
    {
        $prepared = [];

        foreach ($candidates as $candidate) {
            $name = mb_trim((string) ($candidate['name'] ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException('A scanned interaction rule requires a name.');
            }

            $selector = is_string($candidate['selector'] ?? null) ? $candidate['selector'] : null;
            $pagePattern = is_string($candidate['page_pattern'] ?? null) ? $candidate['page_pattern'] : null;

            if (($selector !== null && mb_strlen($selector, '8bit') > 255)
                || ($pagePattern !== null && mb_strlen($pagePattern, '8bit') > 255)) {
                continue;
            }

            $settings = is_array($candidate['settings'] ?? null) ? $candidate['settings'] : [];
            $settings['scanner_confidence'] = $candidate['confidence'] ?? null;
            $settings['scanner_confidence_note'] = $candidate['confidence_note'] ?? null;

            $prepared[] = [
                'base' => mb_substr(Str::slug($name) ?: 'interaction-rule', 0, 220),
                'name' => mb_substr($name, 0, 255),
                'selector' => $selector,
                'page_pattern' => $pagePattern,
                'settings' => $settings,
            ];
        }

        return $prepared;
    }

    /** @param Builder<SignalInteractionRule> $query
     * @param  list<array{base:string,name:string,selector:string|null,page_pattern:string|null,settings:array<string,mixed>}>  $prepared
     * @return array<string, true>
     */
    private function existingSlugs(Builder $query, array $prepared): array
    {
        $bases = array_values(array_unique(array_column($prepared, 'base')));

        if ($bases === []) {
            return [];
        }

        $slugs = (clone $query)->where(function (Builder $query) use ($bases): void {
            foreach ($bases as $base) {
                $query->orWhere('slug', $base)->orWhere('slug', 'like', $base . '-%');
            }
        })->pluck('slug')->all();

        return array_fill_keys(array_map('strval', $slugs), true);
    }

    /** @param array<string, true> $existingSlugs
     * @param  array<string, int>  $nextSuffixes
     */
    private function nextSlug(string $base, array &$existingSlugs, array &$nextSuffixes): string
    {
        $suffix = $nextSuffixes[$base] ?? 1;

        do {
            $slug = $suffix === 1 ? $base : $base . '-' . $suffix;
            $suffix++;
        } while (isset($existingSlugs[$slug]));

        $nextSuffixes[$base] = $suffix;
        $existingSlugs[$slug] = true;

        return $slug;
    }

    private function lockRecord(string $id): SignalInteractionRule
    {
        $query = SignalInteractionRule::query();

        if (SignalInteractionRule::ownerScopeConfig()->enabled) {
            $owner = OwnerContext::resolveConfigured(SignalInteractionRule::ownerScopeConfig());
            OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Signals administration requires an owner context.');

            $query = $owner === null
                ? $query->globalOnly()
                : $query->forOwner($owner, includeGlobal: false);
        }

        return $query->lockForUpdate()->findOrFail($id);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return Arr::only($data, [
            'tracked_property_id', 'name', 'slug', 'description',
            'trigger_type', 'event_name', 'event_category', 'selector',
            'page_pattern', 'settings', 'sort_order', 'is_active',
        ]);
    }
}
