<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Listeners;

use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Entry\Elements\Entry;
use Rias\CraftStaticCache\Invalidator;

readonly class InvalidateStaticCacheForElement
{
    public function __construct(
        private Invalidator $invalidator,
    ) {}

    public function handle(ElementCachesInvalidated $event): void
    {
        $this->invalidator->clearTags(array_values($event->tags));

        if ($event->element instanceof Entry) {
            $this->invalidator->clearRulesForEntry($event->element);
        }
    }
}
