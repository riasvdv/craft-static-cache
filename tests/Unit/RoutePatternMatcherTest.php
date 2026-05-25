<?php

declare(strict_types=1);

use Rias\CraftStaticCache\Http\RoutePatternMatcher;

it('matches route patterns', function (string $path, mixed $pattern, bool $expected) {
    $matcher = app(RoutePatternMatcher::class);

    expect($matcher->matches($path, $pattern))->toBe($expected);
})->with([
    'Craft route token uri parts' => [
        'news/example-post',
        ['news/', ['slug', '[^\/]+']],
        true,
    ],
    'associative uri parts' => [
        'products/example-product',
        ['uriParts' => ['products/', ['slug', '[^\/]+']]],
        true,
    ],
    'string wildcard pattern' => [
        'news/2026/example-post',
        'news/*',
        true,
    ],
    'root string pattern' => [
        '/',
        '/',
        true,
    ],
    'non matching literal segment' => [
        'events/example-post',
        ['news/', ['slug', '[^\/]+']],
        false,
    ],
    'invalid pattern' => [
        'news/example-post',
        123,
        false,
    ],
]);
