<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

use Closure;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use Illuminate\Support\Collection;
use Rias\CraftStaticCache\Http\RoutePatternMatcher;
use Rias\CraftStaticCache\Models\CacheEntry;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;
use Rias\CraftStaticCache\Stores\StaticCacheStore;

readonly class Invalidator
{
    public function __construct(
        private CacheEntryRepository $cacheEntries,
        private StaticCacheStore $store,
        private Configuration $config,
        private RoutePatternMatcher $patterns,
        private CacheState $state,
    ) {}

    public function clearAll(): int
    {
        return $this->clearEntries(fn () => $this->cacheEntries->all(), ['static-cache']);
    }

    public function clearUrl(string $url): int
    {
        $path = str(parse_url($url, PHP_URL_PATH) ?: $url)
            ->trim('/')
            ->start('/')
            ->when(
                fn ($path): bool => $path->value() !== '/',
                fn ($path) => $path->rtrim('/'),
            )
            ->value();

        return $this->clearEntries(fn () => $this->cacheEntries->all()
            ->filter(static fn (CacheEntry $entry): bool => $entry->path === $path || $entry->url === $url));
    }

    /** @param list<string> $tags */
    public function clearTags(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        return $this->clearEntries(fn () => $this->cacheEntries->forTags($tags), $tags);
    }

    public function clearRules(string $ruleKey = 'all'): int
    {
        $rules = $this->rulesFor($ruleKey);

        if ($rules === []) {
            return 0;
        }

        return $this->clearEntries(fn () => $this->cacheEntries->all()
            ->filter(fn (CacheEntry $entry): bool => $this->patterns->matchesAny(
                ltrim($entry->path, '/'),
                $rules,
            )));
    }

    public function clearRulesForEntry(Entry $entry): int
    {
        $handle = $entry->getSection()?->handle;

        if ($handle === null) {
            return 0;
        }

        return $this->clearRules($handle);
    }

    /**
     * @param Closure(): Collection<array-key, CacheEntry> $select
     * @param list<string> $tags
     */
    private function clearEntries(Closure $select, array $tags = []): int
    {
        return $this->state->invalidate(function () use ($select, $tags): int {
            if ($tags !== []) {
                TagDependency::invalidate($tags);
            }

            $entries = $select();

            foreach ($entries as $entry) {
                $this->store->forget($entry->key, $entry->filePath);
            }

            return $entries->count();
        });
    }

    /** @return list<mixed> */
    private function rulesFor(string $key): array
    {
        $rules = $this->config->invalidationRules();
        $selected = $rules[$key] ?? ($key === 'all' ? $rules['all'] ?? [] : []);

        if ($selected instanceof Collection) {
            $selected = $selected->all();
        }

        return is_array($selected) ? array_values($selected) : [];
    }
}
