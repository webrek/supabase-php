<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\RealtimeException;
use Supabase\Realtime\RealtimeClient;
use Supabase\Tests\Support\MockClient;
use Supabase\Tests\Support\MockWebSocketConnection;
use Supabase\Tests\Support\MockWebSocketConnectionFactory;

function restClient(MockClient $http): Client
{
    $factory = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

test('broadcast() POSTs the message to /realtime/v1/api/broadcast with apikey and bearer', function () {
    $http = new MockClient();
    $http->queue(new Response(202, [], ''));

    restClient($http)->realtime()->broadcast('room-1', 'cursor', ['x' => 1]);

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/realtime/v1/api/broadcast')
        ->and($request->getHeaderLine('apikey'))->toBe('ANON')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer ANON')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and((string) $request->getBody())
        ->toBe('{"messages":[{"topic":"room-1","event":"cursor","payload":{"x":1},"private":false}]}');
});

test('broadcast() strips the realtime: prefix, flags private messages and encodes an empty payload as an object', function () {
    $http = new MockClient();
    $http->queue(new Response(202, [], ''));

    restClient($http)->realtime()->broadcast('realtime:room-1', 'ping', [], private: true);

    $request = $http->lastRequest;
    \assert($request !== null);
    expect((string) $request->getBody())
        ->toBe('{"messages":[{"topic":"room-1","event":"ping","payload":{},"private":true}]}');
});

test('broadcast() is authorised as the user when the client acts as one', function () {
    $http = new MockClient();
    $http->queue(new Response(202, [], ''));

    restClient($http)->withAccessToken('USER_JWT')->realtime()->broadcast('room-1', 'cursor', ['x' => 1], private: true);

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT')
        ->and($request->getHeaderLine('apikey'))->toBe('ANON');
});

test('broadcast() throws RealtimeException on a non-2xx response', function () {
    $http = new MockClient();
    $http->queue(new Response(401, ['Content-Type' => 'application/json'], '{"message":"unauthorized"}'));

    expect(fn () => restClient($http)->realtime()->broadcast('room-1', 'cursor', []))
        ->toThrow(RealtimeException::class, 'unauthorized');
});

test('broadcast() rejects an empty topic or event before sending', function () {
    $http = new MockClient();
    $rt = restClient($http)->realtime();

    expect(fn () => $rt->broadcast('', 'cursor', []))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $rt->broadcast('room-1', ' ', []))->toThrow(\InvalidArgumentException::class)
        ->and($http->requests)->toBe([]);
});

test('broadcast() needs the Transport when the RealtimeClient is built by hand', function () {
    $rt = new RealtimeClient(new MockWebSocketConnectionFactory(new MockWebSocketConnection()), 'https://demo.supabase.co', 'ANON');

    expect(fn () => $rt->broadcast('room-1', 'cursor', []))->toThrow(RealtimeException::class, 'Transport');
});
