<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Http\Middleware;

use Closure;
use CraftCms\Cms\Support\Facades\ElementCaches;
use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Rias\CraftStaticCache\CacheContextFactory;
use Rias\CraftStaticCache\Configuration;
use Rias\CraftStaticCache\Data\CacheContext;
use Rias\CraftStaticCache\Http\ResponseEligibility;
use Rias\CraftStaticCache\Stores\StaticCacheStore;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

readonly class CacheStaticResponse
{
    public function __construct(
        private Configuration $config,
        private ResponseEligibility $eligibility,
        private CacheContextFactory $cacheContext,
        private StaticCacheStore $store,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->config->enabled() || ! $this->eligibility->requestIsCacheable($request)) {
            return $next($request);
        }

        $context = $this->cacheContext->make($request);
        $cached = $this->store->get($context);

        if ($cached) {
            return $cached;
        }

        $cacheStore = Cache::store($this->config->lockStore())->getStore();

        if (! $cacheStore instanceof LockProvider) {
            return $this->renderAndStore($request, $next, $context);
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

        ElementCaches::startCollectingCacheInfo();

        try {
            $response = $next($request);
            [$dependency] = ElementCaches::stopCollectingCacheInfo();
        } catch (Throwable $throwable) {
            $this->stopCollectingQuietly();

            throw $throwable;
        }

        if ($response instanceof Response && $this->eligibility->responseIsCacheable($response)) {
            $tags = $this->tags($dependency);
            $this->store->put($context, $response, $tags);
            $response->headers->set('X-Static-Cache', 'MISS');
        }

        return $response;
    }

    /** @return list<string> */
    private function tags(?TagDependency $dependency): array
    {
        $tags = ['static-cache'];

        if ($dependency === null) {
            return $tags;
        }

        foreach ($dependency->tags as $tag) {
            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }

    private function stopCollectingQuietly(): void
    {
        try {
            ElementCaches::stopCollectingCacheInfo();
        } catch (Throwable) {
        }
    }
}
