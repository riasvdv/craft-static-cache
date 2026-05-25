<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Tests;

use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use Override;
use Yiisoft\Aliases\Aliases;

abstract class TestCase extends PluginTestCase
{
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
