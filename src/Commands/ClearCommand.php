<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use Rias\CraftStaticCache\Invalidator;

class ClearCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:static-cache:clear';

    protected $description = 'Clear all Craft Static Cache entries.';

    public function handle(Invalidator $invalidator): int
    {
        $count = $invalidator->clearAll();

        $this->components->info("Cleared {$count} static cache entries.");

        return self::SUCCESS;
    }
}
