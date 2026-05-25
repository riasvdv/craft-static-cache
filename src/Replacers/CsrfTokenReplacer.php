<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Replacers;

use Rias\CraftStaticCache\Contracts\Replacer;
use Rias\CraftStaticCache\Data\CacheContext;

class CsrfTokenReplacer implements Replacer
{
    private const string Placeholder = '%%STATIC_CACHE_CSRF_TOKEN%%';

    public function prepare(string $html, CacheContext $context): string
    {
        $token = csrf_token();

        if ($token === null || $token === '') {
            return $html;
        }

        return str_replace($token, self::Placeholder, $html);
    }

    public function replace(string $html, CacheContext $context): string
    {
        return str_replace(self::Placeholder, csrf_token() ?? '', $html);
    }
}
