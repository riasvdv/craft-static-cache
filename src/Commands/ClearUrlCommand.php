<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Commands;

use Illuminate\Console\Command;
use Rias\CraftStaticCache\Invalidator;

class ClearUrlCommand extends Command
{
    protected $signature = 'static-cache:clear-url {url}';

    protected $description = 'Clear Craft Static Cache entries for a URL or path.';

    public function handle(Invalidator $invalidator): int
    {
        /** @var string $url */
        $url = $this->argument('url');
        $count = $invalidator->clearUrl($url);

        $this->components->info("Cleared {$count} static cache entries.");

        return self::SUCCESS;
    }
}
