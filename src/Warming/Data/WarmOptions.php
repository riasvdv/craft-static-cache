<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Warming\Data;

use SensitiveParameter;

readonly class WarmOptions
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $concurrency,
        public int $timeout,
        public bool $verify,
        public array $headers = [],
        public ?string $user = null,
        #[SensitiveParameter]
        public ?string $password = null,
    ) {}
}
