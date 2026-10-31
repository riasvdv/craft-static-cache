<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as SiteRequest;
use Illuminate\Support\Facades\Http;
use Rias\CraftStaticCache\CacheContextFactory;
use Rias\CraftStaticCache\Stores\StaticCacheStore;
use Symfony\Component\HttpFoundation\Response;

it('warms only uncached configured URLs by default with request options', function () {
    config()->set('craft.static-cache.warming.urls', [
        '/ok',
        '/missing',
        'https://external.test/ignored',
    ]);
    config()->set('craft.static-cache.warming.headers', [
        'X-Configured' => 'yes',
    ]);

    app(StaticCacheStore::class)->put(
        app(CacheContextFactory::class)->make(SiteRequest::create('/missing')),
        new Response('<html>Missing</html>'),
        ['static-cache'],
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

    app(StaticCacheStore::class)->put(
        app(CacheContextFactory::class)->make(SiteRequest::create('/cached')),
        new Response('<html>Cached</html>'),
        ['static-cache'],
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

it('does not forward warming headers through redirects', function (string $destination, int $status) {
    config()->set('craft.static-cache.warming.urls', ['/redirect']);
    config()->set('craft.static-cache.warming.headers', ['X-Api-Key' => 'private-key']);

    Http::preventStrayRequests();
    Http::fake([
        'https://localhost/redirect' => Http::response('', $status, ['Location' => $destination]),
        $destination => Http::response('Redirect destination'),
    ]);

    $this
        ->artisan('craft:static-cache:warm')
        ->expectsOutputToContain('Warmed 0/1 static cache URL.')
        ->expectsOutputToContain('1 warm request failed.')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(
        fn (Request $request): bool => $request->url() === 'https://localhost/redirect'
        && $request->hasHeader('X-Api-Key', 'private-key'),
    );
    Http::assertNotSent(fn (Request $request): bool => $request->url() === $destination);
})->with([
    'external' => ['https://external.test/receive', 302],
    'metadata service' => ['http://169.254.169.254/latest/meta-data/', 307],
    'other local port' => ['http://127.0.0.1:8080/receive', 308],
    'same site' => ['https://localhost/receive', 301],
]);

it('rejects invalid numeric warming options before making requests', function (string $option, string $value) {
    config()->set('craft.static-cache.warming.urls', ['/ok']);

    Http::preventStrayRequests();
    Http::fake();

    $this
        ->artisan('craft:static-cache:warm', ["--{$option}" => $value])
        ->expectsOutputToContain("The --{$option} option must be a positive integer.")
        ->assertExitCode(2);

    Http::assertNothingSent();
})->with(['concurrency', 'timeout'])->with([
    'zero' => '0',
    'negative' => '-1',
    'fraction' => '1.5',
    'non-numeric' => 'many',
    'numeric prefix' => '2requests',
    'empty' => '',
    'overflow' => '999999999999999999999999999999',
]);
