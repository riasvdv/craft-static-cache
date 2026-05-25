<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Rias\CraftStaticCache\Data\CacheContext;
use Rias\CraftStaticCache\Stores\StaticFilePublisher;

it('publishes static files only for file delivery', function () {
    $basePath = storage_path('framework/testing/static-cache');

    File::deleteDirectory($basePath);

    config()->set('craft.static-cache.disk.path', $basePath);
    config()->set('craft.static-cache.publish', true);

    $publisher = app(StaticFilePublisher::class);
    $request = Request::create('/news?page=2', server: ['HTTP_HOST' => 'example.test']);
    $context = new CacheContext(
        key: 'key',
        url: '/news?page=2',
        host: 'example.test',
        path: '/news',
        query: 'page=2',
        siteId: null,
        request: $request,
    );

    $filePath = $publisher->publish($context, '<html>cached</html>');

    expect($filePath)
        ->not
        ->toBeNull()
        ->and(is_file($filePath))
        ->toBeTrue()
        ->and(file_get_contents($filePath))
        ->toBe('<html>cached</html>');

    $publisher->forget($filePath);

    expect(is_file($filePath))->toBeFalse();

    config()->set('craft.static-cache.publish', false);

    expect($publisher->publish($context, '<html>cached</html>'))->toBeNull();

    File::deleteDirectory($basePath);
});

it('publishes files to rewrite-compatible paths', function (
    string $host,
    string $path,
    string $query,
    string $expectedPath,
) {
    $basePath = storage_path('framework/testing/static-cache-paths/'.sha1($expectedPath));

    File::deleteDirectory($basePath);

    config()->set('craft.static-cache.disk.path', $basePath);
    config()->set('craft.static-cache.publish', true);

    $publisher = app(StaticFilePublisher::class);
    $request = Request::create($path.($query !== '' ? "?{$query}" : ''), server: ['HTTP_HOST' => $host]);
    $context = new CacheContext(
        key: 'key',
        url: $path.($query !== '' ? "?{$query}" : ''),
        host: $host,
        path: $path,
        query: $query,
        siteId: null,
        request: $request,
    );

    expect($publisher->publish($context, '<html>cached</html>'))
        ->toBe($basePath.$expectedPath);

    File::deleteDirectory($basePath);
})->with([
    'nested path with query' => [
        'example.test',
        '/news',
        'page=2',
        '/example.test/news_page=2.html',
    ],
    'nested directory without query' => [
        'example.test',
        '/blog/posts/latest',
        '',
        '/example.test/blog/posts/latest_.html',
    ],
    'root path without query' => [
        'example.test',
        '/',
        '',
        '/example.test/_.html',
    ],
    'host with unsupported characters' => [
        'preview:8080',
        '/news',
        '',
        '/preview-8080/news_.html',
    ],
]);
