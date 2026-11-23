<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

use Closure;
use FOS\HttpCache\ProxyClient\Invalidation\ClearCapable;
use FOS\HttpCache\ProxyClient\Invalidation\PurgeCapable;
use FOS\HttpCache\ProxyClient\Invalidation\TagCapable;

/**
 * The `static-cache` HTTP cache client, so the static cache can be purged through `HttpCache::invalidator()`
 * and combined with a CDN in a `MultiplexerClient`.
 *
 * Like other FOSHttpCache clients, it queues invalidations until {@see flush()}.
 */
class StaticCacheProxyClient implements ClearCapable, PurgeCapable, TagCapable
{
    /** @var list<Closure(): int> */
    private array $queue = [];

    public function __construct(
        private readonly Invalidator $invalidator,
    ) {}

    public function invalidateTags(array $tags): static
    {
        $tags = array_values(array_map(strval(...), $tags));
        $this->queue[] = fn (): int => $this->invalidator->clearTags($tags);

        return $this;
    }

    public function purge(string $url, array $headers = []): static
    {
        $this->queue[] = fn (): int => $this->invalidator->clearUrl($url);

        return $this;
    }

    public function clear(): static
    {
        $this->queue[] = fn (): int => $this->invalidator->clearAll();

        return $this;
    }

    /**
     * @return int The number of cached entries cleared.
     */
    public function flush(): int
    {
        $queue = $this->queue;
        $this->queue = [];

        $cleared = 0;

        foreach ($queue as $invalidation) {
            $cleared += $invalidation();
        }

        return $cleared;
    }
}
