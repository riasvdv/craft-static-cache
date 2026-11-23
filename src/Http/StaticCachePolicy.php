<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Http;

use CraftCms\Cms\HttpCache\DefaultCachePolicy;
use Illuminate\Http\Request;
use Override;
use Rias\CraftStaticCache\Configuration;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caches anonymous HTML pages.
 *
 * Unlike {@see DefaultCachePolicy}, it stores public responses without a lifetime, which stay cached until they're
 * invalidated or an element they show expires.
 */
class StaticCachePolicy extends DefaultCachePolicy
{
    public function __construct(
        private readonly Configuration $config,
        private readonly RoutePatternMatcher $patterns,
    ) {}

    #[Override]
    public function isRequestCacheable(Request $request): bool
    {
        if (! parent::isRequestCacheable($request)) {
            return false;
        }

        if ($request->isSiteRequest() !== true || $request->isActionRequest() === true) {
            return false;
        }

        if ($request->isPreview() === true || $request->getHadToken() === true) {
            return false;
        }

        if ($request->user()) {
            return false;
        }

        return ! $this->patterns->matchesAny($request->decodedPath(), $this->config->excludedUriParts());
    }

    #[Override]
    public function isResponseCacheable(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($response->headers->has('Set-Cookie')) {
            return false;
        }

        foreach (['private', 'no-store', 'no-cache'] as $directive) {
            if ($response->headers->hasCacheControlDirective($directive)) {
                return false;
            }
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if ($contentType === '') {
            return true;
        }

        return str_contains(strtolower($contentType), 'text/html');
    }
}
