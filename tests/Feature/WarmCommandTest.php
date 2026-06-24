<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Rias\CraftStaticCache\Data\CacheContext;
use Rias\CraftStaticCache\Data\CachedResponse;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;

it('warms only uncached configured URLs by default with request options', function () {
    config()->set('craft.static-cache.warming.urls', [
        '/ok',
        '/missing',
        'https://external.test/ignored',
    ]);
    config()->set('craft.static-cache.warming.headers', [
        'X-Configured' => 'yes',
    ]);

    app(CacheEntryRepository::class)->put(
        new CacheContext(
            key: 'missing-key',
            url: '/missing',
            host: 'localhost',
            path: '/missing',
            query: '',
            siteId: 1,
            request: request(),
        ),
        new CachedResponse(
            content: '<html>Missing</html>',
            status: 200,
            headers: [],
            tags: ['static-cache'],
            filePath: null,
        ),
    );

    $seenOptions = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request, array $options) use (&$seenOptions) {
        $seenOptions[$request->url()] = $options;

        return Http::response(
            body: 'OK',
            status: str_ends_with($request->url(), '/missing') ? 500 : 200,
        );
    });

    $this
        ->artisan('craft:static-cache:warm', [
            '--header' => ['X-CLI: yes'],
            '--user' => 'deploy',
            '--password' => 'secret',
            '--insecure' => true,
            '--timeout' => '3',
            '--concurrency' => '2',
        ])
        ->expectsOutputToContain('Ignored external warm URL [https://external.test/ignored].')
        ->expectsOutputToContain('Warmed 1/1 static cache URL.')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(
        fn (Request $request): bool => (
            $request->url() === 'https://localhost/ok'
            && $request->hasHeader('X-Configured', 'yes')
            && $request->hasHeader('X-CLI', 'yes')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('deploy:secret'))
        ),
    );

    expect($seenOptions['https://localhost/ok']['verify'] ?? null)
        ->toBeFalse()
        ->and($seenOptions['https://localhost/ok']['timeout'] ?? null)
        ->toBe(3)
        ->and($seenOptions['https://localhost/ok']['connect_timeout'] ?? null)
        ->toBe(3);
});

it('warms cached configured URLs when all is requested', function () {
    config()->set('craft.static-cache.warming.urls', ['/cached']);

    app(CacheEntryRepository::class)->put(
        new CacheContext(
            key: 'cached-key',
            url: '/cached',
            host: 'localhost',
            path: '/cached',
            query: '',
            siteId: 1,
            request: request(),
        ),
        new CachedResponse(
            content: '<html>Cached</html>',
            status: 200,
            headers: [],
            tags: ['static-cache'],
            filePath: null,
        ),
    );

    Http::preventStrayRequests();
    Http::fake([
        'https://localhost/cached' => Http::response('OK'),
    ]);

    $this
        ->artisan('craft:static-cache:warm', [
            '--all' => true,
        ])
        ->expectsOutputToContain('Warmed 1/1 static cache URL.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});
