<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Warming;

use CraftCms\Cms\Site\Data\Site;
use Illuminate\Support\Collection;
use Uri\Rfc3986\Uri;

readonly class WarmableSiteMatcher
{
    /** @param Collection<int, Site> $sites */
    public function match(Uri $uri, Collection $sites): ?Site
    {
        $host = $uri->getHost();
        $scheme = strtolower($uri->getScheme() ?? 'https');

        if ($host === null || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $match = null;
        $matchLength = -1;
        $urlPath = trim(rawurldecode($uri->getPath()), '/');
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        foreach ($sites as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl === null) {
                continue;
            }

            $baseUri = Uri::parse($baseUrl);

            if (! $baseUri instanceof Uri || strcasecmp($baseUri->getHost() ?? '', $host) !== 0) {
                continue;
            }

            // Warming sends credentials, so require the configured origin rather
            // than Craft's fallback to a site on a different scheme or port.
            $baseScheme = strtolower($baseUri->getScheme() ?? 'https');
            $basePort = $baseUri->getPort() ?? ($baseScheme === 'https' ? 443 : 80);

            if ($baseScheme !== $scheme || $basePort !== $port) {
                continue;
            }

            $basePath = trim($baseUri->getPath(), '/');

            if ($basePath !== '' && $urlPath !== $basePath && ! str_starts_with($urlPath, "{$basePath}/")) {
                continue;
            }

            if (strlen($basePath) < $matchLength) {
                continue;
            }

            if (strlen($basePath) === $matchLength && (! $site->primary || $match?->primary)) {
                continue;
            }

            $match = $site;
            $matchLength = strlen($basePath);
        }

        return $match;
    }
}
