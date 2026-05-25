<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Str;
use Illuminate\Http\Request;
use Rias\CraftStaticCache\Data\CacheContext;
use RuntimeException;
use Throwable;

readonly class CacheContextFactory
{
    public function __construct(
        private Configuration $config,
    ) {}

    public function make(Request $request): CacheContext
    {
        $host = Str::lower($request->getHost() ?: 'default');
        $path = Str::start(Str::trim($request->decodedPath(), '/'), '/');
        $path = $path === '/' ? '/' : Str::rtrim($path, '/');
        $query = $this->normalizedQuery($request);
        $siteId = $this->currentSiteId();
        $url = $query === '' ? $path : "{$path}?{$query}";

        return new CacheContext(
            key: $this->fingerprint($host, $path, $query, $siteId),
            url: $url,
            host: $host,
            path: $path,
            query: $query,
            siteId: $siteId,
            request: $request,
        );
    }

    private function normalizedQuery(Request $request): string
    {
        $query = $request->query->all();

        if ($query === []) {
            return '';
        }

        if ($this->config->queryParametersIgnoredByDefault()) {
            $allowed = array_flip($this->config->allowedQueryParameters());
            $query = array_intersect_key($query, $allowed);
        }

        ksort($query);

        return http_build_query($query);
    }

    private function fingerprint(string $host, string $path, string $query, ?int $siteId): string
    {
        $payload = Json::encode([
            'host' => $host,
            'path' => $path,
            'query' => $query,
            'siteId' => $siteId,
        ], JSON_THROW_ON_ERROR);

        if (! is_string($payload)) {
            throw new RuntimeException('Unable to encode static cache key payload.');
        }

        return hash('sha256', $payload);
    }

    private function currentSiteId(): ?int
    {
        try {
            return Sites::getCurrentSite()->id;
        } catch (Throwable) {
            return null;
        }
    }
}
