<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

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
    ) {}

    public function clearAll(): int
    {
        $entries = $this->cacheEntries->all();

        $entries->each(fn (CacheEntry $entry): null => $this->forgetEntry($entry));
        $this->cacheEntries->clear();
        TagDependency::invalidate('static-cache');

        return $entries->count();
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

        $entries = $this->cacheEntries->all()
            ->filter(static fn (CacheEntry $entry): bool => $entry->path === $path || $entry->url === $url);

        $entries->each(fn (CacheEntry $entry): null => $this->forgetEntry($entry));

        return $entries->count();
    }

    /** @param list<string> $tags */
    public function clearTags(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        TagDependency::invalidate($tags);
        $entries = $this->cacheEntries->forTags($tags);
        $entries->each(fn (CacheEntry $entry): null => $this->forgetEntry($entry));

        return $entries->count();
    }

    public function clearRules(string $ruleKey = 'all'): int
    {
        $rules = $this->rulesFor($ruleKey);

        if ($rules === []) {
            return 0;
        }

        $entries = $this->cacheEntries->all()
            ->filter(fn (CacheEntry $entry): bool => $this->patterns->matchesAny(
                ltrim($entry->path, '/'),
                $rules,
            ));

        $entries->each(fn (CacheEntry $entry): null => $this->forgetEntry($entry));

        return $entries->count();
    }

    public function clearRulesForEntry(Entry $entry): int
    {
        $handle = $entry->getSection()?->handle;

        if ($handle === null) {
            return 0;
        }

        return $this->clearRules($handle);
    }

    private function forgetEntry(CacheEntry $entry): void
    {
        $this->store->forget($entry->key, $entry->filePath);
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
