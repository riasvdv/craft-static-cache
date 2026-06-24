<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Prompts\Support\Logger;
use Rias\CraftStaticCache\Warming\Data\WarmableUrl;
use Rias\CraftStaticCache\Warming\Data\WarmFailure;
use Rias\CraftStaticCache\Warming\Data\WarmOptions;
use Rias\CraftStaticCache\Warming\Data\WarmResult;
use Rias\CraftStaticCache\Warming\WarmableUrlCollector;
use Rias\CraftStaticCache\Warming\Warmer;
use function Laravel\Prompts\info;
use function Laravel\Prompts\task;
use function Laravel\Prompts\warning;

class WarmCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:static-cache:warm
        {--include=* : Only warm paths matching these route patterns.}
        {--exclude=* : Skip paths matching these route patterns.}
        {--all : Warm all URLs, including URLs that are already indexed.}
        {--concurrency= : Number of concurrent HTTP requests.}
        {--timeout= : Request timeout in seconds.}
        {--insecure : Skip SSL certificate verification.}
        {--header=* : Header to send with each request, e.g. "Authorization: Bearer token".}
        {--user= : HTTP basic authentication username.}
        {--password= : HTTP basic authentication password.}';

    protected $description = 'Warm Craft Static Cache by requesting same-site URLs.';

    public function handle(
        WarmableUrlCollector $collector,
        Warmer $warmer,
    ): int {
        $options = $this->warmOptions();

        $collected = $collector->collect(
            include: $this->patterns('include'),
            exclude: $this->patterns('exclude'),
            uncached: ! $this->option('all'),
        );

        foreach ($collected['warnings'] as $warning) {
            warning($warning);
        }

        $urls = $collected['urls'];

        if ($urls === []) {
            info('No warmable static cache URLs found.');

            return self::SUCCESS;
        }

        $result = task(
            label: 'Warming '.count($urls).' static cache '.Str::plural('URL', count($urls)),
            callback: fn (Logger $log): WarmResult => $this->warmAndLogUrls($warmer, $urls, $options, $log),
            limit: 0,
            keepSummary: true,
            subLabel: "Concurrency: {$options->concurrency}, timeout: {$options->timeout}s",
        );

        info(
            "Warmed {$result->successful}/{$result->total} static cache ".Str::plural('URL', $result->total).'.',
        );

        if ($result->failed() > 0) {
            warning("{$result->failed()} warm ".Str::plural('request', $result->failed()).' failed.');
        }

        return self::SUCCESS;
    }

    /** @param list<WarmableUrl> $urls */
    private function warmAndLogUrls(
        Warmer $warmer,
        array $urls,
        WarmOptions $options,
        Logger $log,
    ): WarmResult {
        $result = $warmer->warm($urls, $options);
        $failures = $this->failuresByUrl($result);

        foreach ($urls as $url) {
            $failure = $failures[$url->url] ?? null;
            $summary = $this->urlSummary($url->url, $result->durationFor($url->url));

            if ($failure === null) {
                $log->success($summary);

                continue;
            }

            $status = $failure->status !== null ? " [{$failure->status}]" : '';

            $log->warning("{$summary}{$status}: {$failure->message}");
        }

        return $result;
    }

    private function urlSummary(string $url, ?int $duration): string
    {
        if ($duration === null) {
            return $url;
        }

        return $url.$this->grey(" - {$duration}ms");
    }

    private function grey(string $value): string
    {
        return "\e[90m{$value}\e[0m";
    }

    /** @return array<string, WarmFailure> */
    private function failuresByUrl(WarmResult $result): array
    {
        $failures = [];

        foreach ($result->failures as $failure) {
            $failures[$failure->url] = $failure;
        }

        return $failures;
    }

    private function warmOptions(): WarmOptions
    {
        return new WarmOptions(
            concurrency: $this->integerOption('concurrency') ?? max(1, Config::integer(
                'craft.static-cache.warming.concurrency',
                10,
            )),
            timeout: $this->integerOption('timeout') ?? max(1, Config::integer(
                'craft.static-cache.warming.timeout',
                10,
            )),
            verify: ! $this->option('insecure'),
            headers: $this->headers(),
            user: $this->stringOption('user'),
            password: $this->stringOption('password'),
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        /** @var array<string, string> $headers */
        $headers = array_replace(
            Config::array('craft.static-cache.warming.headers', []),
            $this->headersFromStrings($this->stringOptions('header')),
        );

        return $headers;
    }

    /** @param list<string> $headers */
    private function headersFromStrings(array $headers): array
    {
        return collect($headers)
            ->mapWithKeys(function (string $header): array {
                [$name, $value] = explode(':', $header, 2);

                return [trim($name) => trim($value)];
            })
            ->all();
    }

    /** @return list<string> */
    private function patterns(string $option): array
    {
        $patterns = [];

        foreach ($this->stringOptions($option) as $value) {
            foreach (explode(',', $value) as $pattern) {
                $pattern = trim($pattern);

                if ($pattern !== '') {
                    $patterns[] = $pattern;
                }
            }
        }

        return $patterns;
    }

    /** @return list<string> */
    private function stringOptions(string $option): array
    {
        $values = [];
        $optionValues = $this->option($option);

        foreach (is_array($optionValues) ? $optionValues : [] as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function stringOption(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function integerOption(string $option): ?int
    {
        $value = $this->option($option);

        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
