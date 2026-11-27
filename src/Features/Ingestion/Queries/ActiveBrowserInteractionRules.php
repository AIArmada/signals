<?php

declare(strict_types=1);

namespace AIArmada\Signals\Features\Ingestion\Queries;

use AIArmada\Signals\Features\Ingestion\Api\BrowserInteractionRules;
use AIArmada\Signals\Features\Ingestion\Models\SignalInteractionRule;

final readonly class ActiveBrowserInteractionRules implements BrowserInteractionRules
{
    public function forProperty(string $propertyId): array
    {
        if (! (bool) config('signals.integrations.browser.interaction_tracking.enabled', true)) {
            return [];
        }

        $includeRulesWithoutSelector = (bool) config('signals.integrations.browser.interaction_tracking.include_rules_without_selector', false);

        /** @var list<array<string, mixed>> $rules */
        $rules = SignalInteractionRule::query()
            ->forOwner()
            ->where('is_active', true)
            ->where(function ($query) use ($propertyId): void {
                $query->whereNull('tracked_property_id')
                    ->orWhere('tracked_property_id', $propertyId);
            })
            ->when(! $includeRulesWithoutSelector, static function ($query): void {
                $query->where(function ($innerQuery): void {
                    $innerQuery->where('trigger_type', 'media')
                        ->orWhere(static function ($selectorQuery): void {
                            $selectorQuery->whereNotNull('selector')->where('selector', '!=', '');
                        });
                });
            })
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get([
                'id',
                'name',
                'slug',
                'trigger_type',
                'event_name',
                'event_category',
                'selector',
                'page_pattern',
                'settings',
            ])
            ->map(static fn (SignalInteractionRule $rule): array => [
                'id' => (string) $rule->getKey(),
                'name' => (string) $rule->name,
                'slug' => (string) $rule->slug,
                'trigger_type' => (string) $rule->trigger_type,
                'event_name' => (string) $rule->event_name,
                'event_category' => $rule->event_category,
                'selector' => $rule->selector,
                'page_pattern' => $rule->page_pattern,
                'settings' => is_array($rule->settings) ? $rule->settings : null,
            ])
            ->values()
            ->all();

        return $rules;
    }
}
