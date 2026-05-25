<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\ElementCaches;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;

it('invalidates cached entry pages when the entry is saved again', function () {
    $this->withoutExceptionHandling();

    $basePath = storage_path('framework/testing/static-cache-feature');
    $suffix = Str::lower(Str::random(8));
    $path = "news/{$suffix}";

    File::deleteDirectory($basePath);

    config()->set('craft.static-cache.enabled', true);
    config()->set('craft.static-cache.publish', true);
    config()->set('craft.static-cache.disk.path', $basePath);
    config()->set('craft.static-cache.queryParameters.ignoreByDefault', true);
    config()->set('craft.static-cache.queryParameters.allow', []);

    $site = Sites::getPrimarySite();

    $entryType = new EntryType([
        'name' => 'Article',
        'handle' => "staticCacheArticle{$suffix}",
    ]);

    expect(EntryTypes::saveEntryType($entryType))->toBeTrue();

    $section = new Section([
        'name' => 'Static Cache News',
        'handle' => "staticCacheNews{$suffix}",
        'type' => SectionType::Channel,
        'enableVersioning' => false,
    ]);

    $section->setSiteSettings([
        new SectionSiteSettings([
            'siteId' => $site->id,
            'hasUrls' => true,
            'uriFormat' => $path,
            'template' => 'news/_entry',
        ]),
    ]);
    $section->setEntryTypes([$entryType]);

    if (! Sections::saveSection($section)) {
        $this->fail(json_encode($section->errors()->getMessages(), JSON_THROW_ON_ERROR));
    }

    $entry = new Entry([
        'sectionId' => $section->id,
        'siteId' => $site->id,
        'title' => 'Example post',
        'slug' => $suffix,
    ]);
    $entry->setTypeId($entryType->id);
    $entry->setAuthorId(1);

    expect(Elements::saveElement($entry, false))->toBeTrue();

    Route::middleware('craft.web')->get($path, function () use ($entry) {
        ElementCaches::collectCacheInfoForElement($entry);

        return response('<html>Example post</html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    });

    $cacheEntries = app(CacheEntryRepository::class);

    $this
        ->get($path)
        ->assertOk()
        ->assertHeader('X-Static-Cache', 'MISS')
        ->assertSee('Example post');

    expect($cacheEntries->count())->toBe(1);

    $this
        ->get($path)
        ->assertOk()
        ->assertHeader('X-Static-Cache', 'HIT')
        ->assertSee('Example post');

    $cacheEntry = $cacheEntries->all()->first();

    expect($cacheEntry)
        ->not
        ->toBeNull()
        ->and($cacheEntry->tags)
        ->toContain('element::'.Entry::class, "element::{$entry->id}")
        ->and($cacheEntry->filePath)
        ->toBe("{$basePath}/localhost/news/{$suffix}_.html")
        ->and(is_file($cacheEntry->filePath))
        ->toBeTrue();

    $entry->title = 'Updated post';

    expect(Elements::saveElement($entry, false))
        ->toBeTrue()
        ->and($cacheEntries->count())
        ->toBe(0)
        ->and(is_file($cacheEntry->filePath))
        ->toBeFalse();

    File::deleteDirectory($basePath);
});
