<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Http\Middleware\HandleTemplateRequest;
use CraftCms\Cms\Plugin\Plugin;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Rias\CraftStaticCache\Commands\ClearCommand;
use Rias\CraftStaticCache\Commands\ClearTagsCommand;
use Rias\CraftStaticCache\Commands\ClearUrlCommand;
use Rias\CraftStaticCache\Commands\StatusCommand;
use Rias\CraftStaticCache\Commands\WarmCommand;
use Rias\CraftStaticCache\Data\CachedResponse;
use Rias\CraftStaticCache\Http\Middleware\CacheStaticResponse;
use Rias\CraftStaticCache\Listeners\InvalidateStaticCacheForElement;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;

class StaticCache extends Plugin
{
    public string $schemaVersion = '1.0.2';

    protected array $commands = [
        ClearCommand::class,
        ClearTagsCommand::class,
        ClearUrlCommand::class,
        StatusCommand::class,
        WarmCommand::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/static-cache.php', 'craft.static-cache');

        $this->registerSerializableClasses([
            CachedResponse::class,
        ]);

        $this->forgetResolvedCacheStores();
    }

    /**
     * Cache stores copy `cache.serializable_classes` when they are built, so a store
     * resolved before this plugin registers would read cached responses back as
     * incomplete classes. Forget those stores so they rebuild with the full list.
     * Array stores are kept because forgetting them would discard their contents.
     */
    private function forgetResolvedCacheStores(): void
    {
        if (! $this->app->resolved('cache')) {
            return;
        }

        $stores = array_keys(array_filter(
            Config::array('cache.stores', []),
            fn (mixed $store): bool => ($store['driver'] ?? null) !== 'array',
        ));

        $this->app->make(CacheManager::class)->forgetDriver($stores);
        Container::getInstance()->forgetInstance('cache.store');
    }

    public function boot(): void
    {
        $this->registerMiddleware();
        $this->registerAboutCommandInformation();

        Event::listen(ElementCachesInvalidated::class, InvalidateStaticCacheForElement::class);
    }

    protected function getCacheOptions(): array
    {
        return [
            'static-cache' => [
                'label' => 'Static cache',
                'info' => 'Full-page HTML responses cached by Craft Static Cache.',
                'action' => fn (): int => app(Invalidator::class)->clearAll(),
            ],
        ];
    }

    private function registerAboutCommandInformation(): void
    {
        AboutCommand::add('Craft Static Cache', static function (): array {
            $config = app(Configuration::class);

            return [
                'Enabled' => self::formatBooleanForConsole($config->enabled()),
                'Publish' => self::formatBooleanForConsole($config->publish()),
                'Entries' => (string) app(CacheEntryRepository::class)->count(),
            ];
        });
    }

    private static function formatBooleanForConsole(bool $value): string
    {
        return $value
            ? '<fg=green;options=bold>ENABLED</>'
            : '<fg=yellow;options=bold>DISABLED</>';
    }

    private function registerMiddleware(): void
    {
        $router = app(Router::class);
        $middleware = $router->getMiddlewareGroups()['craft.web'] ?? [];
        $middleware = array_values(array_filter(
            $middleware,
            fn (string $class): bool => $class !== CacheStaticResponse::class,
        ));

        $position = array_search(HandleTemplateRequest::class, $middleware, true);

        if ($position === false) {
            $router->pushMiddlewareToGroup('craft.web', CacheStaticResponse::class);

            return;
        }

        array_splice($middleware, $position, 0, [CacheStaticResponse::class]);

        $router->middlewareGroup('craft.web', $middleware);
    }
}
