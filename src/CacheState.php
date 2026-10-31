<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

readonly class CacheState
{
    public function __construct(
        private Configuration $config,
    ) {}

    public function version(): ?string
    {
        if (! Cache::store($this->config->lockStore())->getStore() instanceof LockProvider) {
            return null;
        }

        return $this->synchronized(fn (): string => Cache::store($this->config->lockStore())
            ->rememberForever('static-cache:generation', static fn (): string => (string) Str::uuid()));
    }

    public function isCurrent(string $version): bool
    {
        return Cache::store($this->config->lockStore())->get('static-cache:generation') === $version;
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function invalidate(Closure $operation): mixed
    {
        return $this->synchronized(function () use ($operation): mixed {
            Cache::store($this->config->lockStore())->forever('static-cache:generation', (string) Str::uuid());

            return $operation();
        });
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function synchronized(Closure $operation): mixed
    {
        $store = Cache::store($this->config->lockStore())->getStore();

        if (! $store instanceof LockProvider) {
            return $operation();
        }

        $lock = $store->lock('static-cache:mutation', 60);
        $lock->block($this->config->lockWaitSeconds());

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
