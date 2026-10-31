<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Pest\Plugins\Parallel;

use function Orchestra\Testbench\default_skeleton_path;

// Craft's plugin installer shares this directory across workers. Create it
// before they start so their first plugin registrations cannot race on mkdir.
if (! Parallel::isWorker()) {
    new Filesystem()->ensureDirectoryExists(default_skeleton_path().'/vendor/craftcms');
}

pest()->extends(Rias\CraftStaticCache\Tests\TestCase::class)
    ->in('Feature', 'Unit');
