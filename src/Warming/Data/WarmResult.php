<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Warming\Data;

readonly class WarmResult
{
    /**
     * @param  list<WarmFailure>  $failures
     * @param  array<string, int>  $durations
     */
    public function __construct(
        public int $total,
        public int $successful,
        public array $failures,
        public array $durations = [],
    ) {}

    public function failed(): int
    {
        return count($this->failures);
    }

    public function durationFor(string $url): ?int
    {
        return $this->durations[$url] ?? null;
    }
}
