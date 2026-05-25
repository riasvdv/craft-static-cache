<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Stores;

use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use CraftCms\DependencyAwareCache\Facades\DependencyCache;
use Rias\CraftStaticCache\Configuration;
use Rias\CraftStaticCache\Data\CacheContext;
use Rias\CraftStaticCache\Data\CachedResponse;
use Rias\CraftStaticCache\Replacers\Replacers;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;
use Symfony\Component\HttpFoundation\Response;

readonly class StaticCacheStore
{
    public function __construct(
        private Configuration $config,
        private Replacers $replacers,
        private CacheEntryRepository $index,
        private StaticFilePublisher $files,
    ) {}

    public function get(CacheContext $context): ?CachedResponse
    {
        $cached = DependencyCache::store($this->config->store())->get($this->cacheKey($context->key));

        if (! $cached instanceof CachedResponse) {
            return null;
        }

        $cached = $this->ensureFilePublished($context, $cached);

        return new CachedResponse(
            content: $this->replacers->replace($cached->content, $context),
            status: $cached->status,
            headers: $cached->headers,
            tags: $cached->tags,
            filePath: $cached->filePath,
        );
    }

    public function put(CacheContext $context, Response $response, array $tags): void
    {
        $content = $this->replacers->prepare((string) $response->getContent(), $context);
        $filePath = $this->files->publish($context, $content);
        $cached = new CachedResponse(
            content: $content,
            status: $response->getStatusCode(),
            headers: $this->cacheableHeaders($response),
            tags: $tags,
            filePath: $filePath,
        );

        DependencyCache::store($this->config->store())->forever(
            $this->cacheKey($context->key),
            $cached,
            new TagDependency($tags),
        );

        $this->index->put($context, $cached);
    }

    public function forget(string $key, ?string $filePath = null): void
    {
        DependencyCache::store($this->config->store())->forget($this->cacheKey($key));
        $this->files->forget($filePath);
        $this->index->forget($key);
    }

    private function ensureFilePublished(CacheContext $context, CachedResponse $cached): CachedResponse
    {
        if (! $this->config->publish()) {
            return $cached;
        }

        if ($cached->filePath && is_file($cached->filePath)) {
            return $cached;
        }

        $filePath = $this->files->publish($context, $cached->content);

        if ($filePath === null) {
            return $cached;
        }

        $cached = new CachedResponse(
            content: $cached->content,
            status: $cached->status,
            headers: $cached->headers,
            tags: $cached->tags,
            filePath: $filePath,
        );

        DependencyCache::store($this->config->store())->forever(
            $this->cacheKey($context->key),
            $cached,
            new TagDependency($cached->tags),
        );

        $this->index->put($context, $cached);

        return $cached;
    }

    private function cacheKey(string $key): string
    {
        return "static-cache:{$key}";
    }

    private function cacheableHeaders(Response $response): array
    {
        $headers = [];

        foreach (['content-type', 'cache-control', 'etag', 'last-modified'] as $name) {
            $values = $response->headers->all($name);

            if ($values !== []) {
                $headers[$name] = implode(', ', $values);
            }
        }

        return $headers;
    }
}
