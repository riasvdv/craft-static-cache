<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Warming\Data;

readonly class WarmableUrl
{
    public function __construct(
        public string $url,
        public string $host,
        public string $path,
        public string $query,
        public ?int $siteId,
    ) {}

    public function key(): string
    {
        return implode('|', [
            $this->host,
            $this->path,
            $this->query,
            (string) $this->siteId,
        ]);
    }
}
