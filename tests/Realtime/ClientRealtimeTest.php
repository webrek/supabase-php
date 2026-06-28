<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\RealtimeException;
use Supabase\Realtime\RealtimeClient;
use Supabase\Tests\Support\MockClient;
use Supabase\Tests\Support\MockWebSocketConnection;
use Supabase\Tests\Support\MockWebSocketConnectionFactory;

test('realtime() throws when no WebSocketConnectionFactory is configured', function () {
    expect(fn () => (new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: new MockClient())))->realtime())
        ->toThrow(RealtimeException::class);
});

test('realtime() returns a memoized RealtimeClient when a factory is provided', function () {
    $client = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: new MockClient(),
        webSocketFactory: new MockWebSocketConnectionFactory(new MockWebSocketConnection()),
    ));

    $rt = $client->realtime();
    expect($rt)->toBeInstanceOf(RealtimeClient::class)
        ->and($client->realtime())->toBe($rt)
        ->and($rt->buildUrl())->toBe('wss://demo.supabase.co/realtime/v1/websocket?apikey=ANON&vsn=1.0.0');
});

test('ClientOptions __debugInfo exposes the webSocketFactory key', function () {
    $opts = new ClientOptions(webSocketFactory: new MockWebSocketConnectionFactory(new MockWebSocketConnection()));
    expect(array_key_exists('webSocketFactory', $opts->__debugInfo()))->toBeTrue();
});

test('ClientOptions realtimeHeartbeatInterval is passed through to RealtimeClient', function () {
    $factory = new MockWebSocketConnectionFactory(new MockWebSocketConnection());
    $options = new ClientOptions(
        httpClient: new MockClient(),
        webSocketFactory: $factory,
        realtimeHeartbeatInterval: 10.0,
    );
    $client = new Client('https://example.supabase.co', 'key', $options);
    $realtime = $client->realtime();
    $debug = $realtime->__debugInfo();
    expect($debug['heartbeatInterval'])->toBe(10.0);
});
