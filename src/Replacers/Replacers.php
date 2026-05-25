<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Replacers;

use Rias\CraftStaticCache\Configuration;
use Rias\CraftStaticCache\Contracts\Replacer;
use Rias\CraftStaticCache\Data\CacheContext;

readonly class Replacers
{
    public function __construct(
        private Configuration $config,
    ) {}

    public function prepare(string $html, CacheContext $context): string
    {
        foreach ($this->replacers() as $replacer) {
            $html = $replacer->prepare($html, $context);
        }

        return $html;
    }

    public function replace(string $html, CacheContext $context): string
    {
        foreach ($this->replacers() as $replacer) {
            $html = $replacer->replace($html, $context);
        }

        return $html;
    }

    /** @return list<Replacer> */
    private function replacers(): array
    {
        $replacers = [];

        foreach ($this->config->replacers() as $class) {
            $replacer = app($class);

            if ($replacer instanceof Replacer) {
                $replacers[] = $replacer;
            }
        }

        return $replacers;
    }
}
