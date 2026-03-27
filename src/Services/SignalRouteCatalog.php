<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

final class SignalRouteCatalog
{
    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! is_string($name) || $name === '' || ! $this->isEligibleRoute($route)) {
                continue;
            }

            $options[$name] = sprintf('%s [%s]', $name, $this->normalizeUri($route->uri()));
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * @return array{field:string,operator:string,value:string}|null
     */
    public function conditionForRouteName(string $routeName): ?array
    {
        $route = Route::getRoutes()->getByName($routeName);

        if (! $route instanceof LaravelRoute || ! $this->isEligibleRoute($route)) {
            return null;
        }

        $uri = $this->normalizeUri($route->uri());

        if (! str_contains($uri, '{')) {
            return [
                'field' => 'path',
                'operator' => 'equals',
                'value' => $uri,
            ];
        }

        $prefix = mb_strstr($uri, '{', true);
        $prefix = $prefix === false ? $uri : $prefix;

        if ($prefix === '' || $prefix === false) {
            $prefix = '/';
        }

        if ($prefix !== '/' && ! str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        return [
            'field' => 'path',
            'operator' => 'starts_with',
            'value' => $prefix,
        ];
    }

    private function isEligibleRoute(LaravelRoute $route): bool
    {
        $methods = $route->methods();

        if (! in_array('GET', $methods, true) && ! in_array('HEAD', $methods, true)) {
            return false;
        }

        $uri = mb_ltrim($this->normalizeUri($route->uri()), '/');

        foreach (['api/signals', 'livewire', '_ignition', 'clockwork', 'filament/exports', 'filament/imports'] as $excludedPrefix) {
            if (str_starts_with($uri, $excludedPrefix)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeUri(string $uri): string
    {
        $path = '/' . mb_ltrim($uri, '/');

        if ($path === '//') {
            return '/';
        }

        return $path === '/' ? '/' : mb_rtrim($path, '/');
    }
}
