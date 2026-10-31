<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Rias\CraftStaticCache\CacheContextFactory;
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
        '/example.test/news/index_page=2.html',
    ],
    'nested directory without query' => [
        'example.test',
        '/blog/posts/latest',
        '',
        '/example.test/blog/posts/latest/index_.html',
    ],
    'root path without query' => [
        'example.test',
        '/',
        '',
        '/example.test/index_.html',
    ],
    'host with unsupported characters' => [
        'preview:8080',
        '/news',
        '',
        '/preview-8080/news/index_.html',
    ],
]);

it('keeps path and query variants in separate files', function () {
    $basePath = storage_path('framework/testing/static-cache-collisions');
    config()->set('craft.static-cache.disk.path', $basePath);
    config()->set('craft.static-cache.publish', true);
    config()->set('craft.static-cache.queryParameters.ignoreByDefault', false);

    $factory = app(CacheContextFactory::class);
    $publisher = app(StaticFilePublisher::class);
    $first = $publisher->publish($factory->make(Request::create('/a_b?c=d')), 'body A');
    $second = $publisher->publish($factory->make(Request::create('/a?b_c=d')), 'body B');

    expect($first)
        ->not
        ->toBe($second)
        ->and(file_get_contents($first))
        ->toBe('body A')
        ->and(file_get_contents($second))
        ->toBe('body B');

    $publisher->forget($first);
    expect(file_get_contents($second))->toBe('body B');
    File::deleteDirectory($basePath);
});

it('rejects decoded traversal paths without creating files outside the cache root', function (string $url) {
    $root = storage_path('framework/testing/static-cache-traversal-'.getmypid());
    $basePath = $root.'/static';
    config()->set('craft.static-cache.disk.path', $basePath);
    config()->set('craft.static-cache.publish', true);

    $context = app(CacheContextFactory::class)->make(Request::create($url));
    try {
        expect(app(StaticFilePublisher::class)->publish($context, 'unsafe'))->toBeNull();
        expect(File::exists($root))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
})->with(['/%2e%2e/%2e%2e/escaped', '/safe/%2e%2e/escaped', '/safe%5cescaped']);

it('does not follow cache-directory symlinks outside the publishing root', function () {
    $root = storage_path('framework/testing/static-cache-symlink-'.getmypid());
    File::ensureDirectoryExists($root.'/static/localhost');
    File::ensureDirectoryExists($root.'/external');
    symlink($root.'/external', $root.'/static/localhost/outside');
    config()->set('craft.static-cache.disk.path', $root.'/static');
    config()->set('craft.static-cache.publish', true);

    try {
        $context = app(CacheContextFactory::class)->make(Request::create('/outside/escaped'));
        expect(app(StaticFilePublisher::class)->publish($context, 'unsafe'))->toBeNull();
        expect(File::allFiles($root.'/external'))->toBeEmpty();
    } finally {
        File::deleteDirectory($root);
    }
});

it('atomically replaces published HTML while existing readers finish the old response', function () {
    $basePath = storage_path('framework/testing/static-cache-atomic');
    config()->set('craft.static-cache.disk.path', $basePath);
    config()->set('craft.static-cache.publish', true);
    $publisher = app(StaticFilePublisher::class);
    $context = app(CacheContextFactory::class)->make(Request::create('/atomic'));
    $file = $publisher->publish($context, 'complete old response');
    $reader = fopen($file, 'rb');

    try {
        $publisher->publish($context, 'complete new response');
        expect(stream_get_contents($reader))->toBe('complete old response');
        expect(file_get_contents($file))->toBe('complete new response');
        expect(glob(dirname($file).'/.static-cache-*'))->toBeEmpty();
    } finally {
        fclose($reader);
        File::deleteDirectory($basePath);
    }
});
