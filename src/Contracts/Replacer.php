<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Contracts;

use Rias\CraftStaticCache\Data\CacheContext;

interface Replacer
{
    public function prepare(string $html, CacheContext $context): string;

    public function replace(string $html, CacheContext $context): string;
}
