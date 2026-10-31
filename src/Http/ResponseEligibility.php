<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Http;

use Illuminate\Http\Request;
use Rias\CraftStaticCache\Configuration;
use Symfony\Component\HttpFoundation\Response;

readonly class ResponseEligibility
{
    public function __construct(
        private Configuration $config,
        private RoutePatternMatcher $patterns,
    ) {}

    public function requestIsCacheable(Request $request): bool
    {
        if (! $this->config->enabled()) {
            return false;
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
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

    public function responseIsCacheable(Response $response): bool
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
