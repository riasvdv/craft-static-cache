<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Str;
use Rias\CraftStaticCache\Data\CacheContext;
use Rias\CraftStaticCache\Data\CachedResponse;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;
use Rias\CraftStaticCache\Warming\WarmableUrlCollector;

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
        '/blog',
    ]);

    app(CacheEntryRepository::class)->put(
        new CacheContext(
            key: 'cached-key',
            url: '/news/cached',
            host: 'localhost',
            path: '/news/cached',
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

    $collected = app(WarmableUrlCollector::class)->collect(
        include: ['news/*'],
        exclude: ['news/cached'],
        uncached: true,
    );

    expect($collected['urls'])
        ->toHaveCount(1)
        ->and($collected['urls'][0]->url)
        ->toBe('https://localhost/news/fresh');
});

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
