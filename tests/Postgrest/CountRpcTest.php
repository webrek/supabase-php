<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function crClient(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

test('count issues a HEAD with count Prefer and parses Content-Range', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Range' => '*/42']));
    $n = crClient($http)->from('users')->select('*')->eq('active', true)->count();

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('HEAD')
        ->and($http->lastRequest->getHeaderLine('Prefer'))->toContain('count=exact')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/rest/v1/users?select=%2A&active=eq.true')
        ->and($n)->toBe(42);
});

test('rpc posts params to the function endpoint and decodes the result', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"sum":3}]'));
    $res = crClient($http)->rpc('add', ['a' => 1, 'b' => 2])->execute();

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/rest/v1/rpc/add')
        ->and((string) $http->lastRequest->getBody())->toBe('{"a":1,"b":2}')
        ->and($res)->toBe([['sum' => 3]]);
});
