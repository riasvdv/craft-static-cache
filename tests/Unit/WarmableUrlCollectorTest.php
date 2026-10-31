<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Rias\CraftStaticCache\CacheContextFactory;
use Rias\CraftStaticCache\Data\CachedResponse;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;
use Rias\CraftStaticCache\Stores\StaticCacheStore;
use Rias\CraftStaticCache\Warming\WarmableUrlCollector;
use Symfony\Component\HttpFoundation\Response;

it('collects live entry URLs and configured same-site URLs', function () {
    config()->set('craft.static-cache.queryParameters.ignoreByDefault', true);
    config()->set('craft.static-cache.queryParameters.allow', ['page', 'q']);
    config()->set('craft.static-cache.warming.urls', [
        '/blog?page=2&q=hello%20world&utm_source=newsletter',
        'https://localhost/contact',
        'https://external.test/ignored',
    ]);

    $entry = createWarmableEntry();

    $collected = app(WarmableUrlCollector::class)->collect();
    $blog = collect($collected['urls'])->firstWhere('path', '/blog');

    expect(collect($collected['urls'])->map->url->all())
        ->toContain(
            $entry->getUrl(),
            'https://localhost/contact',
        )
        ->and($blog)
        ->not
        ->toBeNull()
        ->and($blog->query)
        ->toBe('page=2&q=hello%20world')
        ->and($collected['warnings'])
        ->toContain('Ignored external warm URL [https://external.test/ignored].');
});

it('filters collected URLs by patterns and uncached state', function () {
    config()->set('craft.static-cache.warming.urls', [
        '/news/cached',
        '/news/fresh',
        '/news/stale',
        '/news/excluded',
        '/blog',
    ]);

    app(StaticCacheStore::class)->put(
        app(CacheContextFactory::class)->make(Request::create('/news/cached')),
        new Response('<html>Cached</html>'),
        ['static-cache'],
    );

    app(CacheEntryRepository::class)->put(
        app(CacheContextFactory::class)->make(Request::create('/news/stale')),
        new CachedResponse(
            content: '<html>Evicted</html>',
            tags: ['static-cache'],
        ),
    );

    $collected = app(WarmableUrlCollector::class)->collect(
        include: ['news/*'],
        exclude: ['news/excluded'],
        uncached: true,
    );

    expect(collect($collected['urls'])->map->url->all())->toBe([
        'https://localhost/news/fresh',
        'https://localhost/news/stale',
    ]);
});

it('keeps each configured origin distinct and chooses the longest site path', function () {
    $primary = Sites::getPrimarySite();
    $portSite = createWarmableSite('https://localhost:8443/');
    $otherPortSite = createWarmableSite('https://localhost:9443/');
    $httpSite = createWarmableSite('http://localhost:8443/');
    $languageSite = createWarmableSite('https://localhost/en/');
    $nestedSite = createWarmableSite('https://localhost/en/shop/');
    createWarmableSite('https://disabled.test/', enabled: false);

    config()->set('craft.static-cache.warming.urls', [
        '/page',
        'https://localhost:8443/page',
        'https://disabled.test/page',
        'https://localhost/enough/page',
    ]);

    $collected = app(WarmableUrlCollector::class)->collect();
    $siteIdsByUrl = collect($collected['urls'])->mapWithKeys(fn ($url): array => [$url->url => $url->siteId]);

    expect($siteIdsByUrl->all())
        ->toEqual([
            'https://localhost/page' => $primary->id,
            'https://localhost:8443/page' => $portSite->id,
            'https://localhost:9443/page' => $otherPortSite->id,
            'http://localhost:8443/page' => $httpSite->id,
            'https://localhost/en/page' => $languageSite->id,
            'https://localhost/en/shop/page' => $nestedSite->id,
            'https://localhost/enough/page' => $primary->id,
        ])
        ->and($collected['warnings'])
        ->toBe(['Ignored external warm URL [https://disabled.test/page].']);
});

it('rejects unconfigured schemes and ports on an enabled host', function (string $url) {
    config()->set('craft.static-cache.warming.urls', [$url]);

    $collected = app(WarmableUrlCollector::class)->collect();

    expect($collected['urls'])
        ->toBe([])
        ->and($collected['warnings'])
        ->toBe(["Ignored external warm URL [{$url}]."]);
})->with([
    'different scheme' => 'http://localhost/private',
    'different port' => 'https://localhost:8443/private',
    'non-HTTP scheme' => 'ftp://localhost/private',
]);

it('accepts the default HTTPS port as the configured origin', function () {
    config()->set('craft.static-cache.warming.urls', ['https://localhost:443/page']);

    $collected = app(WarmableUrlCollector::class)->collect();

    expect($collected['urls'])
        ->toHaveCount(1)
        ->and($collected['urls'][0]->siteId)
        ->toBe(Sites::getPrimarySite()->id)
        ->and($collected['warnings'])
        ->toBe([]);
});

function createWarmableSite(string $baseUrl, bool $enabled = true): Site
{
    $suffix = Str::lower(Str::random(8));
    $primary = Sites::getPrimarySite();
    $site = new Site([
        'name' => "Warm site {$suffix}",
        'handle' => "warmSite{$suffix}",
        'groupId' => $primary->groupId,
        'language' => 'en-US',
        'baseUrl' => $baseUrl,
        'hasUrls' => true,
        'enabled' => $enabled,
    ]);

    expect(Sites::saveSite($site))->toBeTrue();

    return $site;
}

function createWarmableEntry(): Entry
{
    $suffix = Str::lower(Str::random(8));
    $site = Sites::getPrimarySite();

    $entryType = new EntryType([
        'name' => "Static Cache Warm Article {$suffix}",
        'handle' => "staticCacheWarmArticle{$suffix}",
    ]);

    expect(EntryTypes::saveEntryType($entryType))->toBeTrue();

    $section = new Section([
        'name' => "Static Cache Warm News {$suffix}",
        'handle' => "staticCacheWarmNews{$suffix}",
        'type' => SectionType::Channel,
        'enableVersioning' => false,
    ]);

    $section->setSiteSettings([
        new SectionSiteSettings([
            'siteId' => $site->id,
            'hasUrls' => true,
            'uriFormat' => 'news/{slug}',
            'template' => 'news/_entry',
        ]),
    ]);
    $section->setEntryTypes([$entryType]);

    expect(Sections::saveSection($section))->toBeTrue();

    $entry = new Entry([
        'sectionId' => $section->id,
        'siteId' => $site->id,
        'title' => "Warmable post {$suffix}",
        'slug' => $suffix,
    ]);
    $entry->setTypeId($entryType->id);
    $entry->setAuthorId(1);

    expect(Elements::saveElement($entry, false))->toBeTrue();
    Elements::updateElementSlugAndUri($entry, false, false, false);

    $entry = Entry::find()
        ->id($entry->id)
        ->siteId($site->id)
        ->one();

    expect($entry)->toBeInstanceOf(Entry::class);

    return $entry;
}
