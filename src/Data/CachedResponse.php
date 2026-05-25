<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Data;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CachedResponse implements Responsable
{
    public function __construct(
        public string $content,
        public int $status = 200,
        public array $headers = [],
        public array $tags = [],
        public ?string $filePath = null,
    ) {}

    public function toResponse($request): Response
    {
        $response = new Response($this->content, $this->status, $this->headers);
        $response->headers->set('X-Static-Cache', 'HIT');

        return $response;
    }
}
