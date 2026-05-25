<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Listeners;

use CraftCms\Cms\Utility\Events\ClearCachesOptionsResolving;
use Rias\CraftStaticCache\Invalidator;

readonly class AddStaticCacheClearOption
{
    public function __construct(
        private Invalidator $invalidator,
    ) {}

    public function handle(ClearCachesOptionsResolving $event): void
    {
        $event->options[] = [
            'key' => 'static-cache',
            'label' => 'Static cache',
            'info' => 'Full-page HTML responses cached by Craft Static Cache.',
            'action' => fn (): int => $this->invalidator->clearAll(),
        ];
    }
}
