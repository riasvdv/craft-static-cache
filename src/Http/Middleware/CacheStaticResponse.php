<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Http\Middleware;

use Closure;
use CraftCms\Cms\Http\CacheInfo;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Rias\CraftStaticCache\CacheContextFactory;
use Rias\CraftStaticCache\CacheState;
use Rias\CraftStaticCache\Configuration;
use Rias\CraftStaticCache\Data\CacheContext;
use Rias\CraftStaticCache\Http\StaticCacheProfile;
use Rias\CraftStaticCache\Stores\StaticCacheStore;
use Symfony\Component\HttpFoundation\Response;

readonly class CacheStaticResponse
{
    public function __construct(
        private Configuration $config,
        private StaticCacheProfile $profile,
        private CacheContextFactory $cacheContext,
        private StaticCacheStore $store,
        private CacheState $state,
        private CacheInfo $cacheInfo,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->config->enabled() || ! $this->profile->isRequestCacheable($request)) {
            return $next($request);
        }

        $context = $this->cacheContext->make($request);

        try {
            $cached = $this->store->get($context);
        } catch (LockTimeoutException) {
            return $next($request);
        }

        if ($cached) {
            return $cached;
        }

        if ($request->isMethod('HEAD')) {
            return $next($request);
        }

        $cacheStore = Cache::store($this->config->lockStore())->getStore();

        if (! $cacheStore instanceof LockProvider) {
            return $next($request);
        }

        $lock = $cacheStore->lock("static-cache:{$context->key}", 30);

        try {
            return $lock->block($this->config->lockWaitSeconds(), function () use ($context, $next, $request): mixed {
                return $this->renderAndStore($request, $next, $context);
            });
        } catch (LockTimeoutException) {
            return $next($request);
        }
    }

    private function renderAndStore(Request $request, Closure $next, CacheContext $context): mixed
    {
        $cached = $this->store->get($context);

        if ($cached) {
            return $cached;
        }

        $version = $this->state->version();

        if ($version === null) {
            return $next($request);
        }

        $response = $next($request);

        if ($response instanceof Response && $this->profile->isResponseCacheable($request, $response)) {
            $tags = array_values(array_unique(['static-cache', ...$this->cacheInfo->getTags()]));
            $expiresAt = $this->cacheInfo->getExpiryDate()?->getTimestamp();
            $ttl = $expiresAt !== null ? max(0, $expiresAt - now()->getTimestamp()) : null;

            try {
                if ($this->store->put($context, $response, $tags, $ttl, $version)) {
                    $response->headers->set('X-Static-Cache', 'MISS');
                }
            } catch (LockTimeoutException) {
                // Keep the rendered response when invalidation holds the mutation lock.
            }
        }

        return $response;
    }
}
