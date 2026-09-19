<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\PostgrestException;
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

test('count throws PostgrestException on a 4xx response', function () {
    $http = new MockClient();
    $body = '{"message":"Not Found","code":"PGRST204"}';
    $http->queue(new Response(404, ['Content-Type' => 'application/json'], $body));

    expect(fn () => crClient($http)->from('users')->select('*')->count())
        ->toThrow(PostgrestException::class);
});

test('count parses the range/total Content-Range format', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Range' => '0-9/100']));
    $n = crClient($http)->from('users')->select('*')->count();

    \assert($http->lastRequest !== null);
    expect($n)->toBe(100);
});

test('rpc posts params to the function endpoint and decodes the result', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"sum":3}]'));
    $res = crClient($http)->rpc('add', ['a' => 1, 'b' => 2])->execute();

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/rest/v1/rpc/add')
        ->and((string) $http->lastRequest->getBody())->toBe('{"a":1,"b":2}')
        ->and($http->lastRequest->getHeaderLine('Prefer'))->not->toContain('return=minimal')
        ->and($res)->toBe([['sum' => 3]]);
});

test('rpc scalar() returns the bare value an RPC produces, which execute() cannot represent', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '3'));
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '"ok"'));
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], 'true'));
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], 'null'));
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '3'));
    $client = crClient($http);

    expect($client->rpc('add', ['a' => 1, 'b' => 2])->scalar())->toBe(3)
        ->and($client->rpc('greet')->scalar())->toBe('ok')
        ->and($client->rpc('flag')->scalar())->toBeTrue()
        ->and($client->rpc('nothing')->scalar())->toBeNull()
        ->and($client->rpc('add', ['a' => 1, 'b' => 2])->execute())->toBeNull();
});

test('rpc scalar() rejects a row set and surfaces server errors', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"sum":3}]'));
    $http->queue(new Response(404, ['Content-Type' => 'application/json'], '{"message":"function not found"}'));
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{not json'));
    $client = crClient($http);

    expect(fn () => $client->rpc('rows')->scalar())->toThrow(PostgrestException::class, 'row set')
        ->and(fn () => $client->rpc('missing')->scalar())->toThrow(PostgrestException::class, 'function not found')
        ->and(fn () => $client->rpc('broken')->scalar())->toThrow(PostgrestException::class, 'Invalid JSON');
});
