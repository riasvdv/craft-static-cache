<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Stores;

use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use CraftCms\DependencyAwareCache\Facades\DependencyCache;
use Rias\CraftStaticCache\CacheState;
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
        private CacheState $state,
    ) {}

    public function get(CacheContext $context): ?CachedResponse
    {
        return $this->state->synchronized(function () use ($context): ?CachedResponse {
            $cached = DependencyCache::store($this->config->store())->get($this->cacheKey($context->key));

            if (! $cached instanceof CachedResponse) {
                $entry = $this->index->find($context->key);

                if ($entry !== null) {
                    $this->forget($context->key, $entry->filePath);
                }

                return null;
            }

            $response = clone $this->ensureFilePublished($context, $cached);
            $response->content = $this->replacers->replace($cached->content, $context);

            return $response;
        });
    }

    public function has(string $key): bool
    {
        return DependencyCache::store($this->config->store())->get($this->cacheKey($key)) instanceof CachedResponse;
    }

    public function put(
        CacheContext $context,
        Response $response,
        array $tags,
        ?int $ttl = null,
        ?string $version = null,
    ): bool {
        $version ??= $this->state->version();

        if ($version === null || $ttl !== null && $ttl <= 0) {
            return false;
        }

        $original = (string) $response->getContent();
        $content = $this->replacers->prepare($original, $context);
        $expiresAt = $ttl !== null ? now()->getTimestamp() + $ttl : null;
        $cached = new CachedResponse(
            content: $content,
            status: $response->getStatusCode(),
            headers: $this->cacheableHeaders($response),
            tags: $tags,
            publishable: $content === $original && $ttl === null,
        );

        return $this->state->synchronized(function () use ($context, $cached, $version, $expiresAt): bool {
            if (! $this->state->isCurrent($version)) {
                return false;
            }

            if ($cached->publishable) {
                $cached->filePath = $this->files->publish($context, $cached->content);
            }

            $ttl = $expiresAt !== null ? max(0, $expiresAt - now()->getTimestamp()) : null;
            $this->save($context, $cached, $ttl);

            if (! $this->state->isCurrent($version)) {
                $this->forget($context->key, $cached->filePath);

                return false;
            }

            return true;
        });
    }

    public function forget(string $key, ?string $filePath = null): void
    {
        DependencyCache::store($this->config->store())->forget($this->cacheKey($key));
        $this->files->forget($filePath);
        $this->index->forget($key);
    }

    private function ensureFilePublished(CacheContext $context, CachedResponse $cached): CachedResponse
    {
        if (! $this->config->publish() || ! $cached->publishable) {
            return $cached;
        }

        if ($cached->filePath && is_file($cached->filePath)) {
            return $cached;
        }

        $filePath = $this->files->publish($context, $cached->content);

        if ($filePath === null) {
            return $cached;
        }

        $cached = clone $cached;
        $cached->filePath = $filePath;
        $this->save($context, $cached);

        return $cached;
    }

    private function save(CacheContext $context, CachedResponse $cached, ?int $ttl = null): void
    {
        DependencyCache::store($this->config->store())->put(
            $this->cacheKey($context->key),
            $cached,
            $ttl,
            new TagDependency($cached->tags),
        );

        $this->index->put($context, $cached);
    }

    private function cacheKey(string $key): string
    {
        return "static-cache:v2:{$key}";
    }

    private function cacheableHeaders(Response $response): array
    {
        $headers = $response->headers->all();
        $connectionHeaders = explode(',', (string) $response->headers->get('Connection', ''));

        foreach (array_merge($connectionHeaders, [
            'connection',
            'keep-alive',
            'proxy-authenticate',
            'proxy-authorization',
            'te',
            'trailer',
            'transfer-encoding',
            'upgrade',
            'set-cookie',
            'content-length',
            'date',
            'age',
        ]) as $name) {
            unset($headers[strtolower(trim($name))]);
        }

        return $headers;
    }
}
