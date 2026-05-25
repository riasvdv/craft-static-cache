<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Stores;

use CraftCms\Cms\Support\File;
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
        if (! $this->shouldPublish()) {
            return null;
        }

        $filePath = $this->filePath($context);

        File::ensureDirectoryExists(dirname($filePath), $this->config->diskPermissions());

        if (file_put_contents($filePath, $content, LOCK_EX) === false) {
            return null;
        }

        chmod($filePath, 0o644);

        return $filePath;
    }

    public function forget(?string $filePath): void
    {
        if ($filePath && is_file($filePath)) {
            Files::delete($filePath);
        }
    }

    private function shouldPublish(): bool
    {
        return $this->config->publish();
    }

    private function filePath(CacheContext $context): string
    {
        $base = rtrim($this->config->diskPath(), '/');
        $parts = [
            $base,
            preg_replace('/[^a-z0-9.-]+/i', '-', $context->host) ?: 'default',
        ];

        $path = pathinfo($context->path);
        $directory = trim($path['dirname'] ?? '', '/');

        if ($directory !== '') {
            $parts[] = $directory;
        }

        $slug = $path['basename'];

        return implode('/', $parts)."/{$slug}_{$context->query}.html";
    }
}
