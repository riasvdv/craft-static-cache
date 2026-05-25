<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Rias\CraftStaticCache\CacheContextFactory;

it('builds deterministic keys for the same normalized request', function () {
    config()->set('craft.static-cache.queryParameters.ignoreByDefault', true);
    config()->set('craft.static-cache.queryParameters.allow', ['page']);

    $factory = app(CacheContextFactory::class);

    $first = Request::create('/news?ignored=one&page=2', server: ['HTTP_HOST' => 'Example.test']);
    $second = Request::create('/news?page=2&ignored=two', server: ['HTTP_HOST' => 'example.test']);

    expect($factory->make($first)->key)
        ->toBe($factory->make($second)->key);
});
