<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Commands;

use CraftCms\Cms\Support\Arr;
use Illuminate\Console\Command;
use Rias\CraftStaticCache\Invalidator;

class ClearTagsCommand extends Command
{
    protected $signature = 'static-cache:clear-tags {tags*}';

    protected $description = 'Clear Craft Static Cache entries by dependency tag.';

    public function handle(Invalidator $invalidator): int
    {
        /** @var list<string> $tags */
        $tags = Arr::wrap($this->argument('tags'));
        $count = $invalidator->clearTags($tags);

        $this->components->info("Cleared {$count} static cache entries.");

        return self::SUCCESS;
    }
}
