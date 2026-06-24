<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Warming;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Url;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Rias\CraftStaticCache\Configuration;
use Rias\CraftStaticCache\Http\RoutePatternMatcher;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;
use Rias\CraftStaticCache\Warming\Data\WarmableUrl;
use Uri\Rfc3986\Uri;

readonly class WarmableUrlCollector
{
    public function __construct(
        private Configuration $config,
        private RoutePatternMatcher $patterns,
        private CacheEntryRepository $cacheEntries,
    ) {}

    /**
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     * @return array{urls: list<WarmableUrl>, warnings: list<string>}
     */
    public function collect(array $include = [], array $exclude = [], bool $uncached = false): array
    {
        /** @var Collection<int, Site> $sites */
        $sites = Sites::getAllSites()
            ->filter(fn (Site $site) => $site->hasUrls && $site->getEnabled())
            ->values();

        $urls = [];
        $warnings = [];

        foreach ($this->entryUrls($sites) as $url) {
            $this->add($urls, $url, $sites);
        }

        foreach (Config::array('craft.static-cache.warming.urls', []) as $url) {
            if (Url::isAbsoluteUrl($url)) {
                if (! $this->isSameSiteUrl($url, $sites)) {
                    $warnings[] = "Ignored external warm URL [{$url}].";

                    continue;
                }

                $this->add($urls, $url, $sites);

                continue;
            }

            foreach ($sites as $site) {
                $this->add($urls, Url::siteUrl($url, null, null, $site->id), $sites);
            }
        }

        return [
            'urls' => array_values(array_filter(
                $urls,
                fn (WarmableUrl $url) => $this->shouldWarm($url, $include, $exclude, $uncached),
            )),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return list<string>
     */
    private function entryUrls(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        /** @var iterable<Entry> $entries */
        $entries = Entry::find()
            ->site('*')
            ->status('live')
            ->uri(':notempty:')
            ->drafts(false)
            ->provisionalDrafts(false)
            ->get();

        $urls = [];

        foreach ($entries as $entry) {
            $url = $entry->getUrl();

            if ($url !== null && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, WarmableUrl>  $urls
     * @param  Collection<int, Site>  $sites
     */
    private function add(array &$urls, string $url, Collection $sites): void
    {
        $uri = Uri::parse($url);

        if (! $uri instanceof Uri) {
            return;
        }

        $warmable = $this->normalize($uri, $sites);

        if (! $warmable instanceof WarmableUrl) {
            return;
        }

        $urls[$warmable->key()] = $warmable;
    }

    /**
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     */
    private function shouldWarm(WarmableUrl $url, array $include, array $exclude, bool $uncached): bool
    {
        if ($include !== [] && ! $this->patterns->matchesAny($url->path, $include)) {
            return false;
        }

        if ($exclude !== [] && $this->patterns->matchesAny($url->path, $exclude)) {
            return false;
        }

        return ! $uncached || ! $this->cacheEntries->existsFor($url->host, $url->path, $url->query, $url->siteId);
    }

    /** @param Collection<int, Site> $sites */
    private function normalize(Uri $uri, Collection $sites): ?WarmableUrl
    {
        $host = $uri->getHost();

        if ($host === null) {
            return null;
        }

        $site = $this->siteFor($uri, $host, $sites);

        if (! $site instanceof Site) {
            return null;
        }

        if ($uri->getScheme() === null) {
            $uri = $uri->withScheme('https');
        }

        if ($uri->getPath() === '') {
            $uri = $uri->withPath('/');
        }

        $path = str(rawurldecode($uri->getPath()))
            ->trim('/')
            ->start('/');
        $path = $path->value() === '/' ? '/' : $path->rtrim('/')->value();

        return new WarmableUrl(
            url: $uri->toString(),
            host: $host,
            path: $path,
            query: $this->normalizedQuery($uri->getQuery() ?? ''),
            siteId: $site->id,
        );
    }

    /** @param Collection<int, Site> $sites */
    private function isSameSiteUrl(string $url, Collection $sites): bool
    {
        $uri = Uri::parse($url);
        $host = $uri?->getHost();

        if (! $uri instanceof Uri || $host === null) {
            return false;
        }

        return $this->siteFor($uri, $host, $sites) instanceof Site;
    }

    /** @param Collection<int, Site> $sites */
    private function siteFor(Uri $uri, string $host, Collection $sites): ?Site
    {
        $match = null;
        $matchLength = -1;
        $urlPath = trim($uri->getPath(), '/');

        foreach ($sites as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl === null) {
                continue;
            }

            $baseUri = Uri::parse($baseUrl);

            if (! $baseUri instanceof Uri || $baseUri->getHost() !== $host) {
                continue;
            }

            $basePath = trim($baseUri->getPath(), '/');

            if ($basePath !== '' && $urlPath !== $basePath && ! str_starts_with($urlPath, "{$basePath}/")) {
                continue;
            }

            if (strlen($basePath) <= $matchLength) {
                continue;
            }

            $match = $site;
            $matchLength = strlen($basePath);
        }

        return $match;
    }

    private function normalizedQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $parameters = [];
        parse_str($query, $parameters);

        if ($parameters === []) {
            return '';
        }

        if ($this->config->queryParametersIgnoredByDefault()) {
            $parameters = Arr::only($parameters, $this->config->allowedQueryParameters());
        }

        ksort($parameters);

        return Arr::query($parameters);
    }
}
