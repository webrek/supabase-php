<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;
use Supabase\Tests\Support\MockWebSocketConnection;
use Supabase\Tests\Support\MockWebSocketConnectionFactory;

test('Client __debugInfo does not expose the raw apikey', function () {
    $client = new Client('https://demo.supabase.co', 'SECRET-KEY', new ClientOptions(
        httpClient: new MockClient(),
        webSocketFactory: new MockWebSocketConnectionFactory(new MockWebSocketConnection()),
    ));

    expect(print_r($client->__debugInfo(), true))->not->toContain('SECRET-KEY');
});

test('RealtimeClient __debugInfo does not expose the raw apikey', function () {
    $client = new Client('https://demo.supabase.co', 'SECRET-KEY', new ClientOptions(
        httpClient: new MockClient(),
        webSocketFactory: new MockWebSocketConnectionFactory(new MockWebSocketConnection()),
    ));

    expect(print_r($client->realtime()->__debugInfo(), true))->not->toContain('SECRET-KEY');
});

test('serialize(realtime()) throws LogicException', function () {
    $client = new Client('https://demo.supabase.co', 'SECRET-KEY', new ClientOptions(
        httpClient: new MockClient(),
        webSocketFactory: new MockWebSocketConnectionFactory(new MockWebSocketConnection()),
    ));

    expect(fn () => serialize($client->realtime()))->toThrow(\LogicException::class);
});
