<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Stores;

use Illuminate\Support\Facades\File as Files;
use Rias\CraftStaticCache\Configuration;
use Rias\CraftStaticCache\Data\CacheContext;

readonly class StaticFilePublisher
{
    public function __construct(
        private Configuration $config,
    ) {}

    public function publish(CacheContext $context, string $content): ?string
    {
        if (! $this->config->publish()) {
            return null;
        }

        $filePath = $this->filePath($context);

        if ($filePath === null) {
            return null;
        }

        $temporary = tempnam(dirname($filePath), '.static-cache-');

        if ($temporary === false) {
            return null;
        }

        try {
            if (file_put_contents($temporary, $content) === false || ! chmod($temporary, 0o644)) {
                return null;
            }

            return rename($temporary, $filePath) ? $filePath : null;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function forget(?string $filePath): void
    {
        if ($filePath && is_file($filePath)) {
            Files::delete($filePath);
        }
    }

    private function filePath(CacheContext $context): ?string
    {
        $base = rtrim($this->config->diskPath(), '/');
        $host = preg_replace('/[^a-z0-9.-]+/i', '-', $context->host) ?: 'default';
        $parts = array_values(array_filter(
            explode('/', $host.'/'.trim($context->path, '/')),
            static fn (string $part): bool => $part !== '',
        ));

        if (strpbrk($context->path.$context->query, "\\\0") !== false || str_contains($context->query, '/')) {
            return null;
        }

        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                return null;
            }
        }

        Files::makeDirectory($base, $this->config->diskPermissions(), true, true);
        $root = realpath($base);

        if ($root === false) {
            return null;
        }

        $directory = $root;

        foreach ($parts as $part) {
            $directory .= '/'.$part;

            if (is_link($directory)) {
                return null;
            }

            Files::makeDirectory($directory, $this->config->diskPermissions(), false, true);

            if (! is_dir($directory) || ! str_starts_with((string) realpath($directory), $root.'/')) {
                return null;
            }
        }

        return $directory."/index_{$context->query}.html";
    }
}
