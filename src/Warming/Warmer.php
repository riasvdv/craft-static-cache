<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Warming;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Rias\CraftStaticCache\Warming\Data\WarmableUrl;
use Rias\CraftStaticCache\Warming\Data\WarmFailure;
use Rias\CraftStaticCache\Warming\Data\WarmOptions;
use Rias\CraftStaticCache\Warming\Data\WarmResult;
use Throwable;

readonly class Warmer
{
    /** @param list<WarmableUrl> $urls */
    public function warm(array $urls, WarmOptions $options): WarmResult
    {
        if ($urls === []) {
            return new WarmResult(total: 0, successful: 0, failures: []);
        }

        /** @var array<string, int> $startedAt */
        $startedAt = [];
        /** @var array<string, int> $durations */
        $durations = [];

        $responses = Http::pool(function (Pool $pool) use ($urls, $options, &$startedAt, &$durations): void {
            foreach ($urls as $url) {
                $request = $pool
                    ->as($url->url)
                    ->withHeaders($options->headers)
                    ->timeout($options->timeout)
                    ->connectTimeout($options->timeout)
                    ->withUserAgent('CraftCMS Static Cache warmer')
                    ->beforeSending(function () use ($url, &$startedAt): void {
                        $startedAt[$url->url] = (int) hrtime(true);
                    })
                    ->afterResponse(function (Response $response) use ($url, &$startedAt, &$durations): Response {
                        if (isset($startedAt[$url->url])) {
                            $durations[$url->url] = $this->elapsedMilliseconds($startedAt[$url->url]);
                        }

                        return $response;
                    });

                if (! $options->verify) {
                    $request->withoutVerifying();
                }

                if ($options->user !== null && $options->password !== null) {
                    $request->withBasicAuth($options->user, $options->password);
                }

                $request->get($url->url);
            }
        }, $options->concurrency);

        $successful = 0;
        $failures = [];

        foreach ($responses as $url => $response) {
            $url = (string) $url;

            if (! isset($durations[$url]) && isset($startedAt[$url])) {
                $durations[$url] = $this->elapsedMilliseconds($startedAt[$url]);
            }

            if ($response instanceof Response && $response->successful()) {
                $successful++;

                continue;
            }

            if ($response instanceof Response) {
                $failures[] = new WarmFailure(
                    url: $url,
                    message: $response->reason() ?: 'HTTP request failed.',
                    status: $response->status(),
                );

                continue;
            }

            $failures[] = new WarmFailure(
                url: $url,
                message: $response instanceof Throwable ? $response->getMessage() : 'HTTP request failed.',
            );
        }

        return new WarmResult(
            total: count($urls),
            successful: $successful,
            failures: $failures,
            durations: $durations,
        );
    }

    private function elapsedMilliseconds(int|float|false $startedAt): int
    {
        return max(0, (int) round(((int) hrtime(true) - (int) $startedAt) / 1_000_000));
    }
}
