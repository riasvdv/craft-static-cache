<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

readonly class RoutePatternMatcher
{
    public function __construct(
        private Router $router,
    ) {}

    /** @param list<mixed> $patterns */
    public function matchesAny(string $path, array $patterns): bool
    {
        return array_any($patterns, fn ($pattern) => $this->matches($path, $pattern));
    }

    public function matches(string $path, mixed $pattern): bool
    {
        $route = $this->route($pattern);

        if ($route === null) {
            return false;
        }

        return $route->matches(Request::create('/'.trim($path, '/')), includingMethod: false);
    }

    private function route(mixed $pattern): ?Route
    {
        if (is_string($pattern)) {
            return $this->routeForStringPattern($pattern);
        }

        if (! is_array($pattern)) {
            return null;
        }

        $parts = $pattern['uriParts'] ?? $pattern;

        if (! is_array($parts)) {
            return null;
        }

        $uri = '';
        $wheres = [];

        foreach ($parts as $index => $part) {
            if (is_string($part)) {
                $uri .= $part;

                continue;
            }

            if (! is_array($part)) {
                continue;
            }

            $name = (string) ($part[0] ?? '*');
            $parameter = "staticCache{$index}";
            $uri .= "{{$parameter}}";
            $wheres[$parameter] = (string) (
                $part[1] ?? $this->router->getPatterns()[$name] ?? $this->router->getPatterns()['*'] ?? '[^/]+'
            );
        }

        return $this->router->newRoute('GET', trim($uri, '/') ?: '/', static fn () => null)->where($wheres);
    }

    private function routeForStringPattern(string $pattern): Route
    {
        $parts = explode('*', trim($pattern, '/'));
        $uri = array_shift($parts);
        $wheres = [];

        foreach ($parts as $index => $part) {
            $parameter = "staticCacheWildcard{$index}";
            $uri .= "{{$parameter}}{$part}";
            $wheres[$parameter] = '.*';
        }

        return $this->router->newRoute('GET', trim($uri, '/') ?: '/', static fn () => null)->where($wheres);
    }
}
