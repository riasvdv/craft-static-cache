<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache;

use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Http\Middleware\HandleMatchedElementRoute;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\Utility\Events\ClearCachesOptionsResolving;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Rias\CraftStaticCache\Commands\ClearCommand;
use Rias\CraftStaticCache\Commands\ClearTagsCommand;
use Rias\CraftStaticCache\Commands\ClearUrlCommand;
use Rias\CraftStaticCache\Commands\StatusCommand;
use Rias\CraftStaticCache\Commands\WarmCommand;
use Rias\CraftStaticCache\Data\CachedResponse;
use Rias\CraftStaticCache\Http\Middleware\CacheStaticResponse;
use Rias\CraftStaticCache\Listeners\AddStaticCacheClearOption;
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

    public function registerPlugin(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/static-cache.php', 'craft.static-cache');

        $this->registerSerializableClasses([
            CachedResponse::class,
        ]);
    }

    public function bootPlugin(): void
    {
        $this->registerMiddleware();
        $this->registerAboutCommandInformation();

        Event::listen(ElementCachesInvalidated::class, InvalidateStaticCacheForElement::class);
        Event::listen(ClearCachesOptionsResolving::class, AddStaticCacheClearOption::class);
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

        $position = array_search(HandleMatchedElementRoute::class, $middleware, true);

        if ($position === false) {
            $router->pushMiddlewareToGroup('craft.web', CacheStaticResponse::class);

            return;
        }

        array_splice($middleware, $position, 0, [CacheStaticResponse::class]);

        $router->middlewareGroup('craft.web', $middleware);
    }
}
