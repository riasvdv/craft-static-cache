<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

use CraftCms\Cms\Cms;
use Illuminate\Support\Facades\Config;

class Configuration
{
    public function enabled(): bool
    {
        return Config::boolean('craft.static-cache.enabled', false);
    }

    public function store(): ?string
    {
        $store = Config::get('craft.static-cache.store');

        if ($store === null) {
            return null;
        }

        $store = Config::string('craft.static-cache.store');

        return $store !== '' ? $store : null;
    }

    public function publish(): bool
    {
        return Config::boolean('craft.static-cache.publish', false);
    }

    public function diskPath(): string
    {
        return Config::string('craft.static-cache.disk.path', public_path('static'));
    }

    public function diskPermissions(): int
    {
        return Config::integer('craft.static-cache.disk.permissions', $this->defaultDiskPermissions());
    }

    public function queryParametersIgnoredByDefault(): bool
    {
        return Config::boolean('craft.static-cache.queryParameters.ignoreByDefault', true);
    }

    /** @return list<string> */
    public function allowedQueryParameters(): array
    {
        $parameters = [];

        foreach (Config::array('craft.static-cache.queryParameters.allow', []) as $parameter) {
            if (is_string($parameter)) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    /** @return list<mixed> */
    public function excludedUriParts(): array
    {
        return array_values(Config::array('craft.static-cache.exclude.uriParts', []));
    }

    /** @return array<string, mixed> */
    public function invalidationRules(): array
    {
        $rules = Config::array('craft.static-cache.invalidation.rules', []);

        $normalized = [];

        foreach ($rules as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return list<class-string> */
    public function replacers(): array
    {
        $replacers = [];

        foreach (Config::array('craft.static-cache.replacers', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $replacers[] = $class;
        }

        return $replacers;
    }

    public function lockStore(): ?string
    {
        $store = Config::get('craft.static-cache.locks.store');

        if ($store === null) {
            return null;
        }

        $store = Config::string('craft.static-cache.locks.store');

        return $store !== '' ? $store : null;
    }

    public function lockWaitSeconds(): int
    {
        return max(0, Config::integer('craft.static-cache.locks.waitSeconds', 5));
    }

    private function defaultDiskPermissions(): int
    {
        $permissions = Cms::config()->defaultDirMode;

        if (is_string($permissions)) {
            return intval($permissions, 8);
        }

        return is_int($permissions) ? $permissions : 0o775;
    }
}
