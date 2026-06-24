# Craft Static Cache

[![Latest Version on Packagist](https://img.shields.io/packagist/v/rias/craft-static-cache.svg?style=flat-square)](https://packagist.org/packages/rias/craft-static-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/rias/craft-static-cache.svg?style=flat-square)](https://packagist.org/packages/rias/craft-static-cache)

Full-page static HTML caching for Craft CMS 6.

This package stores eligible HTML responses in Laravel's cache and can optionally publish the rendered HTML to disk. When disk publishing is enabled, your web server can serve cached pages directly before PHP boots.

## Installation

You can install the package via Composer:

```bash
composer require rias/craft-static-cache
```

Install the Craft plugin:

```bash
php craft plugin/install static-cache
```

## Configuration

You can publish the config file with:

```bash
php craft vendor:publish --tag=static-cache
```

This will publish the config file to `config/craft/static-cache.php`.

The published config file looks like this:

```php
<?php

use CraftCms\Cms\Cms;

return [
    'enabled' => true,

    'store' => null,

    'publish' => false,

    'disk' => [
        'path' => public_path('static'),
        'permissions' => Cms::config()->defaultDirMode,
    ],
];
```

The most important options are:

- `enabled`: enables full-page static caching.
- `store`: the Laravel cache store used for canonical cached responses. Use `null` for the default store.
- `publish`: also write cached HTML to disk for web server delivery.
- `disk.path`: the public directory where static HTML files are written.
- `queryParameters.allow`: query parameters that should remain part of the cache key when query parameters are ignored by default.
- `exclude.uriParts`: route patterns that should never be cached.
- `invalidation.rules`: route patterns that should be cleared when related elements change.
- `replacers`: classes that implement `Rias\CraftStaticCache\Contracts\Replacer`.
- `warming.urls`: extra same-site URLs to warm in addition to live entry URLs.
- `warming.concurrency`: number of concurrent HTTP requests used by the warm command.
- `warming.timeout`: warm request timeout in seconds.
- `warming.headers`: headers sent with every warm request.

## Usage

Once `enabled` is set to `true`, the package will cache anonymous `GET` and `HEAD` site requests that return a cacheable HTML response.

Requests are not cached when they are action requests, preview requests, tokenized requests, authenticated requests, or match an excluded URI pattern. Responses are not cached when they are not `200` responses, set cookies, or are non-HTML responses.

Cached entries are tagged with Craft element cache dependencies. When Craft invalidates element caches, related static cache entries are cleared as well.

## Publishing Static Files

Set `publish` to `true` to write cached HTML to disk:

```php
'publish' => true,

'disk' => [
    'path' => public_path('static'),
],
```

Published files follow the following format:

```text
public/static/{host}{request-path}_{query-string}.html
```

For example, `/news?page=2` on `example.test` is written to `public/static/example.test/news_page=2.html`.

## Server Rewrite Rules

Place the rewrite rule before the normal Laravel/Craft front controller fallback.

Use the query-ignoring rules when `queryParameters.ignoreByDefault` is `true` and `queryParameters.allow` is empty. Use the query-aware rules when query parameters should be part of the cache key.

### Nginx

Use this when query parameters are ignored:

```nginx
set $try_location @static;

if ($request_method != GET) {
    set $try_location @not_static;
}

location / {
    try_files $uri $try_location;
}

location @static {
    try_files /static/${host}${uri}_.html $uri $uri/ /index.php?$args;
}

location @not_static {
    try_files $uri /index.php?$args;
}
```

Use this when query parameters are part of the cache key:

```nginx
set $try_location @static;

if ($request_method != GET) {
    set $try_location @not_static;
}

location / {
    try_files $uri $try_location;
}

location @static {
    try_files /static/${host}${uri}_$args.html $uri $uri/ /index.php?$args;
}

location @not_static {
    try_files $uri /index.php?$args;
}
```

### Apache

Use this when query parameters are ignored:

```apache
RewriteCond %{DOCUMENT_ROOT}/static/%{HTTP_HOST}%{REQUEST_URI}_\.html -s
RewriteCond %{REQUEST_METHOD} GET
RewriteRule .* static/%{HTTP_HOST}%{REQUEST_URI}_\.html [L,T=text/html]
```

Use this when query parameters are part of the cache key:

```apache
RewriteCond %{DOCUMENT_ROOT}/static/%{HTTP_HOST}%{REQUEST_URI}_%{QUERY_STRING}\.html -s
RewriteCond %{REQUEST_METHOD} GET
RewriteRule .* static/%{HTTP_HOST}%{REQUEST_URI}_%{QUERY_STRING}\.html [L,T=text/html]
```

If you change `disk.path`, update the `/static` prefix in these rules to match the public path your server can access.

When query parameters are ignored, all query-string variants can resolve to the same published file before PHP boots. When query parameters are part of the cache key, the web server can only serve the file directly when the raw query string matches the normalized cached query string. Other query-string variants fall through to PHP and can still be served from the application cache.

## Invalidation

Craft Static Cache tracks the element cache tags collected while a page is rendered. When Craft invalidates element caches, cached pages with matching dependency tags are cleared automatically. This removes both the Laravel cache entry and any published static file.

Entry changes can also clear related pages through invalidation rules. Rule keys usually match section handles, so saving an entry in the `news` section can clear listing pages like `/news` and `/news/*`.

```php
'invalidation' => [
    'rules' => [
        'news' => [
            'news',
            'news/*',
        ],

        'all' => [
            '/',
            'blog/*',
            ['uriParts' => ['products/', ['slug']]],
        ],
    ],
],
```

String patterns support `*` wildcards. Array patterns use Craft route-token URI parts.

## Replacers

Replacers can transform dynamic fragments before storage and after retrieval. They must implement `Rias\CraftStaticCache\Contracts\Replacer`.

```php
use Rias\CraftStaticCache\Contracts\Replacer;
use Rias\CraftStaticCache\Data\CacheContext;

class MyReplacer implements Replacer
{
    public function prepare(string $html, CacheContext $context): string
    {
        return $html;
    }

    public function replace(string $html, CacheContext $context): string
    {
        return $html;
    }
}
```

Register replacers in the config file:

```php
'replacers' => [
    MyReplacer::class,
],
```

## Cache Warming

Warm the cache before visitors hit uncached pages:

```bash
php craft static-cache:warm
```

The warm command makes real same-site `GET` requests, so normal Craft routing, middleware, response eligibility, replacers, tags, and optional static file publishing all still apply. It warms live Entry URLs across enabled sites, plus any extra URLs configured in `warming.urls`.

```php
'warming' => [
    'urls' => [
        '/',
        '/news',
        '/contact',
    ],

    'concurrency' => 10,
    'timeout' => 10,
    'headers' => [
        'X-Cache-Warmer' => 'deploy',
    ],
],
```

Relative extra URLs are expanded against every enabled site. Absolute extra URLs must match one of the configured Craft site hosts; external URLs are ignored.

Useful command options:

```bash
php craft static-cache:warm --all
php craft static-cache:warm --include="news/*" --exclude="news/private/*"
php craft static-cache:warm --concurrency=5 --timeout=3
php craft static-cache:warm --header="X-Cache-Warmer: deploy"
php craft static-cache:warm --user=deploy --password=secret
php craft static-cache:warm --insecure
```

Failed warm requests are reported as warnings, but the command still exits successfully.

## Commands

You can inspect and clear the cache from the command line:

```bash
php craft static-cache:status
php craft static-cache:warm
php craft static-cache:clear
php craft static-cache:clear-url /news
php craft static-cache:clear-tags static-cache
```

The same status information is also registered with Laravel's `about` command:

```bash
php craft about
```

The package also registers a Craft Clear Caches option for clearing static cache entries from the control panel.

## Testing

```bash
composer test
```

## Changelog

Please see [GitHub Releases](https://github.com/riasvdv/craft-static-cache/releases) for more information on what has changed recently.

## Credits

- [Rias](https://rias.be)
- [All Contributors](../../contributors)

## License

The MIT License (MIT).
