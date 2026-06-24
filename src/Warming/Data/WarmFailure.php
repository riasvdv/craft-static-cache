<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Warming\Data;

readonly class WarmFailure
{
    public function __construct(
        public string $url,
        public string $message,
        public ?int $status = null,
    ) {}
}
