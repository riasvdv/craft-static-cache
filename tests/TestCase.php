<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Tests;

use CraftCms\Cms\Database\Migrations\Install;
use CraftCms\Cms\Database\Migrator;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Site\Data\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Override;
use Yiisoft\Aliases\Aliases;

abstract class TestCase extends PluginTestCase
{
    public function setupInstallsPlugin(): void
    {
        File::deleteDirectory(config_path('craft/project'));
        Schema::dropIfExists('staticcache_tags');
        Schema::dropIfExists('staticcache_entries');

        parent::setupInstallsPlugin();
    }

    #[Override]
    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        Schema::dropIfExists('staticcache_tags');
        Schema::dropIfExists('staticcache_entries');

        /** Craft's install migration creates its own migrations table. */
        Schema::drop('migrations');

        $site = new Site([
            'name' => 'Craft test site',
            'handle' => 'default',
            'language' => 'en-US',
            'baseUrl' => 'https://localhost/',
            'primary' => true,
            'hasUrls' => true,
        ]);

        $testPassword = implode('', ['craftcms', '2018!!']);

        $migration = new Install(
            username: 'craftcms',
            password: $testPassword,
            email: 'support@craftcms.com',
            site: $site,
        )->silent();

        $migrator = app(Migrator::class)->track('craft');
        $migrator->runMigration($migration, 'up');
        $migrator->getRepository()->log('Install', 1);

        foreach ($migrator->getPendingMigrations() as $file) {
            $migrator->getRepository()->log($migrator->getMigrationName($file), 1);
        }

        if (! Schema::hasTable('staticcache_entries')) {
            $pluginMigration = include dirname(__DIR__).'/database/migrations/Install.php';
            $pluginMigration->up();
        }
    }

    #[Override]
    protected function getEnvironmentSetUp($app): void
    {
        $app->afterResolving(Aliases::class, static function (Aliases $aliases): void {
            $craftPath = dirname(__DIR__).'/vendor/craftcms/cms';

            $aliases->set('@craftcms', $craftPath);
            $aliases->set('@package', "{$craftPath}/src");
            $aliases->set('@resources', "{$craftPath}/resources");
            $aliases->set('@icons', "{$craftPath}/resources/icons");
            $aliases->set('@appicons', "{$craftPath}/resources/icons/solid");
            $aliases->set('@migrations', "{$craftPath}/database/migrations");
        });
    }
}
