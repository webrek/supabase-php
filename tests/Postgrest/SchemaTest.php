<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

test('non-public schema sets Accept-Profile on reads', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    $f = new Psr17Factory();
    $c = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $f,
        streamFactory: $f,
        schema: 'analytics',
    ));
    $c->from('events')->select('*')->execute();

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getHeaderLine('Accept-Profile'))->toBe('analytics');
});

test('non-public schema sets Content-Profile on writes', function () {
    $http = new MockClient();
    $http->queue(new Response(201, [], ''));
    $f = new Psr17Factory();
    $c = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $f,
        streamFactory: $f,
        schema: 'analytics',
    ));
    $c->from('events')->insert(['x' => 1])->execute();

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getHeaderLine('Content-Profile'))->toBe('analytics');
});
