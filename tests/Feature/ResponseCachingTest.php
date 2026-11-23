<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\ElementCaches;
use CraftCms\Cms\Support\Facades\HttpCache;
use CraftCms\Cms\Support\Facades\ResponseTagger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Rias\CraftStaticCache\Invalidator;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;
use Rias\CraftStaticCache\StaticCache;

use function CraftCms\Cms\renderString;

beforeEach(function () {
    config()->set('craft.static-cache.enabled', true);
    config()->set('craft.static-cache.publish', true);
    config()->set('craft.static-cache.disk.path', storage_path('framework/testing/response-cache-'.getmypid()));
});

afterEach(function () {
    File::deleteDirectory(config('craft.static-cache.disk.path'));
});

it('serves complete GET responses after a first HEAD request', function () {
    Route::middleware('craft.web')->get('response-head', fn () => response('<html>complete</html>', headers: [
        'Cache-Control' => 'public',
    ]));

    $this->head('/response-head')->assertOk();
    expect(app(CacheEntryRepository::class)->count())->toBe(0);

    $this->get('/response-head')->assertHeader('X-Static-Cache', 'MISS')->assertSee('complete');
    $this->get('/response-head')->assertHeader('X-Static-Cache', 'HIT')->assertSee('complete');
    $this->head('/response-head')->assertHeader('X-Static-Cache', 'HIT')->assertContent('');
    $this->get('/response-head')->assertSee('complete');
});

it('reads responses back from a cache store resolved before the plugin registered', function () {
    config()->set('craft.static-cache.store', 'file');
    /** Applications restrict cache unserialization; Testbench leaves it unrestricted. */
    config()->set('cache.serializable_classes', []);

    Cache::forgetDriver('file');
    Cache::store('file')->flush();

    StaticCache::getInstance()->register();

    Route::middleware('craft.web')->get('response-early-store', fn () => response('<html>early</html>', headers: [
        'Cache-Control' => 'public',
    ]));

    $this->get('/response-early-store')->assertHeader('X-Static-Cache', 'MISS');
    $this->get('/response-early-store')->assertHeader('X-Static-Cache', 'HIT')->assertSee('early');

    Cache::store('file')->flush();
});

it('preserves end-to-end security headers without replaying connection headers', function () {
    Route::middleware('craft.web')->get('response-headers', fn () => response('<html>secure</html>', headers: [
        'Cache-Control' => 'public',
        'Content-Security-Policy' => "default-src 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Connection' => 'X-Transient',
        'X-Transient' => 'connection-specific',
    ]));

    $this->get('/response-headers')->assertHeader('X-Static-Cache', 'MISS');
    $this
        ->get('/response-headers')
        ->assertHeader('X-Static-Cache', 'HIT')
        ->assertHeader('Content-Security-Policy', "default-src 'none'")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Connection')
        ->assertHeaderMissing('X-Transient');
});

it('does not share responses forbidden by cache-control', function (string $policy) {
    $renders = 0;
    Route::middleware('craft.web')->get('response-private', function () use (&$renders, $policy) {
        return response('<html>visitor '.++$renders.'</html>', headers: ['Cache-Control' => $policy]);
    });

    $this->get('/response-private')->assertSee('visitor 1')->assertHeaderMissing('X-Static-Cache');
    $this->get('/response-private')->assertSee('visitor 2')->assertHeaderMissing('X-Static-Cache');
    expect(app(CacheEntryRepository::class)->count())->toBe(0);
})->with(['private', 'public, no-store', 'public, no-cache']);

it('expires at the collected Craft deadline without publishing a timeless file', function () {
    $renders = 0;
    $expiring = false;
    Route::middleware('craft.web')->get('response-expiry', function () use (&$renders, &$expiring) {
        if ($expiring) {
            ElementCaches::setCacheExpiryDate(now()->addSeconds(60));
            ElementCaches::setCacheExpiryDate(now()->addSeconds(10));
            ElementCaches::setCacheExpiryDate(now()->addSeconds(90));
        }

        return response('<html>version '.++$renders.'</html>', headers: ['Cache-Control' => 'public']);
    });

    $this->get('/response-expiry')->assertHeader('X-Static-Cache', 'MISS')->assertSee('version 1');
    $oldFile = app(CacheEntryRepository::class)->all()->first()->filePath;
    expect(is_file($oldFile))->toBeTrue();

    Cache::flush();
    $expiring = true;
    $this->get('/response-expiry')->assertHeader('X-Static-Cache', 'MISS')->assertSee('version 2');
    $this->travel(9)->seconds();
    $this->get('/response-expiry')->assertHeader('X-Static-Cache', 'HIT')->assertSee('version 2');
    $this->travel(1)->seconds();
    // Laravel keeps scoped instances, such as the response tagger and its expiry, between test requests.
    app()->forgetScopedInstances();
    $this->get('/response-expiry')->assertHeader('X-Static-Cache', 'MISS')->assertSee('version 3');

    expect(app(CacheEntryRepository::class)->all()->first()->filePath)->toBeNull();
    expect(is_file($oldFile))->toBeFalse();
    expect(File::allFiles(config('craft.static-cache.disk.path')))->toBeEmpty();
});

it('restores each visitors CSRF token without publishing placeholders to disk', function () {
    Route::middleware('craft.web')->get('response-csrf', fn () => response(
        '<html><input name="_token" value="'.csrf_token().'"></html>',
        headers: ['Cache-Control' => 'public'],
    ));

    $this
        ->withSession(['_token' => str_repeat('a', 40)])
        ->get('/response-csrf')
        ->assertHeader('X-Static-Cache', 'MISS')
        ->assertSee(str_repeat('a', 40));
    $this
        ->withSession(['_token' => str_repeat('b', 40)])
        ->get('/response-csrf')
        ->assertHeader('X-Static-Cache', 'HIT')
        ->assertSee(str_repeat('b', 40))
        ->assertDontSee(str_repeat('a', 40));

    expect(app(CacheEntryRepository::class)->all()->first()->filePath)->toBeNull();
    expect(File::exists(config('craft.static-cache.disk.path')))->toBeFalse();
});

it('does not cache a render invalidated before its dependencies are registered', function () {
    $invalidate = true;
    $content = 'old content';

    Route::middleware('craft.web')->get('response-race', function () use (&$invalidate, &$content) {
        ElementCaches::collectCacheTags(['response-race-entry']);
        $response = response('<html>'.$content.'</html>', headers: ['Cache-Control' => 'public']);

        if ($invalidate) {
            app(Invalidator::class)->clearTags(['response-race-entry']);
        }

        return $response;
    });

    $this->get('/response-race')->assertSee('old content');
    expect(app(CacheEntryRepository::class)->count())->toBe(0);
    expect(File::exists(config('craft.static-cache.disk.path')))->toBeFalse();

    $invalidate = false;
    $content = 'updated content';
    $this->get('/response-race')->assertHeader('X-Static-Cache', 'MISS')->assertSee('updated content');
    $this->get('/response-race')->assertHeader('X-Static-Cache', 'HIT')->assertSee('updated content');
});

it('stores the tags added through the response tagger', function () {
    Route::middleware('craft.web')->get('response-tagged', function () {
        ElementCaches::collectCacheTags(['element::response-tagged']);
        ResponseTagger::addTags(['from-php']);

        return response(
            renderString(
                "{% cache globally using key 'response-tagged' %}{% do ResponseTagger.addTags(['from-twig']) %}{% endcache %}<html>tagged</html>",
            ),
            headers: ['Cache-Control' => 'public'],
        );
    });

    // The second render takes `from-twig` from the template cache.
    foreach ([1, 2] as $render) {
        app()->forgetScopedInstances();
        $this->get('/response-tagged')->assertHeader('X-Static-Cache', 'MISS');

        expect(app(CacheEntryRepository::class)->all()->first()->tags)
            ->toContain('static-cache', 'element::response-tagged', 'from-php', 'from-twig');

        app(Invalidator::class)->clearTags(['from-twig']);

        expect(app(CacheEntryRepository::class)->count())->toBe(0);
    }
});

it('purges entries through the static-cache HTTP cache client', function () {
    foreach (['nav', 'footer'] as $tag) {
        Route::middleware('craft.web')->get("response-{$tag}", function () use ($tag) {
            ResponseTagger::addTags([$tag]);

            return response("<html>{$tag}</html>", headers: ['Cache-Control' => 'public']);
        });
    }

    $warm = function (): void {
        foreach (['/response-nav', '/response-footer'] as $uri) {
            // Laravel keeps scoped instances, such as the response tagger, between test requests.
            app()->forgetScopedInstances();
            $this->get($uri);
        }
    };

    $warm();
    $this->get('/response-nav')->assertHeader('X-Static-Cache', 'HIT');

    expect(HttpCache::invalidator('static-cache')->invalidateTags(['nav'])->flush())->toBe(1);
    $this->get('/response-nav')->assertHeader('X-Static-Cache', 'MISS');
    $this->get('/response-footer')->assertHeader('X-Static-Cache', 'HIT');

    expect(HttpCache::invalidator('static-cache')->invalidatePath('https://localhost/response-footer')->flush())
        ->toBe(1);
    $this->get('/response-footer')->assertHeader('X-Static-Cache', 'MISS');

    $warm();
    expect(HttpCache::invalidator('static-cache')->clearCache()->flush())
        ->toBe(2)
        ->and(app(CacheEntryRepository::class)->count())
        ->toBe(0)
        ->and(File::allFiles(config('craft.static-cache.disk.path')))
        ->toBeEmpty();
});
