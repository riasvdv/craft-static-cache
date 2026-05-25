<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Commands;

use Illuminate\Console\Command;
use Rias\CraftStaticCache\Configuration;
use Rias\CraftStaticCache\Repositories\CacheEntryRepository;

class StatusCommand extends Command
{
    protected $signature = 'static-cache:status';

    protected $description = 'Show Craft Static Cache status.';

    public function handle(Configuration $config, CacheEntryRepository $index): int
    {
        $this->newLine();
        $this->components->twoColumnDetail('  <fg=green;options=bold>Craft Static Cache</>');
        $this->components->twoColumnDetail('Enabled', $this->formatBoolean($config->enabled()));
        $this->components->twoColumnDetail('Publish', $this->formatBoolean($config->publish()));
        $this->components->twoColumnDetail('Entries', (string) $index->count());

        return self::SUCCESS;
    }

    private function formatBoolean(bool $value): string
    {
        return $value
            ? '<fg=green;options=bold>ENABLED</>'
            : '<fg=yellow;options=bold>DISABLED</>';
    }
}
