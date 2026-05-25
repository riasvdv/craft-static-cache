<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Repositories;

use Illuminate\Support\Collection;
use Rias\CraftStaticCache\Data\CacheContext;
use Rias\CraftStaticCache\Data\CachedResponse;
use Rias\CraftStaticCache\Models\CacheEntry;

class CacheEntryRepository
{
    public function put(CacheContext $context, CachedResponse $response): void
    {
        CacheEntry::query()
            ->getConnection()
            ->transaction(static function () use ($context, $response): void {
                $entry = CacheEntry::query()->updateOrCreate(
                    ['key' => $context->key],
                    [
                        'url' => $context->url,
                        'host' => $context->host,
                        'path' => $context->path,
                        'query' => $context->query,
                        'siteId' => $context->siteId,
                        'filePath' => $response->filePath,
                        'tags' => array_values(array_unique($response->tags)),
                    ],
                );

                $entry->tags()->delete();

                foreach (array_unique($response->tags) as $tag) {
                    $entry->tags()->create(['tag' => $tag]);
                }
            });
    }

    public function forget(string $key): void
    {
        CacheEntry::query()
            ->getConnection()
            ->transaction(static function () use ($key): void {
                $entry = CacheEntry::query()->find($key);

                if (! $entry instanceof CacheEntry) {
                    return;
                }

                $entry->tags()->delete();
                $entry->delete();
            });
    }

    public function clear(): void
    {
        CacheEntry::query()
            ->getConnection()
            ->transaction(static function (): void {
                CacheEntry::query()->each(static function (CacheEntry $entry): void {
                    $entry->tags()->delete();
                    $entry->delete();
                });
            });
    }

    public function find(string $key): ?CacheEntry
    {
        return CacheEntry::query()->find($key);
    }

    public function all(): Collection
    {
        return CacheEntry::query()
            ->orderBy('url')
            ->get();
    }

    /** @param list<string> $tags */
    public function forTags(array $tags): Collection
    {
        if ($tags === []) {
            return collect();
        }

        return CacheEntry::query()
            ->whereHas('tags', static function ($query) use ($tags): void {
                $query->whereIn('tag', $tags);
            })
            ->get();
    }

    public function count(): int
    {
        return CacheEntry::query()->count();
    }
}
