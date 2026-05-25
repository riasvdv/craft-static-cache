<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Data;

use Illuminate\Http\Request;

class CacheContext
{
    public function __construct(
        public string $key,
        public string $url,
        public string $host,
        public string $path,
        public string $query,
        public ?int $siteId,
        public Request $request,
    ) {}
}
