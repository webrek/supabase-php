<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;
use Supabase\Tests\Support\MockWebSocketConnection;
use Supabase\Tests\Support\MockWebSocketConnectionFactory;

test('mock connection records sends, returns queued frames then null, and tracks connect', function () {
    $conn = new MockWebSocketConnection();
    expect($conn)->toBeInstanceOf(WebSocketConnection::class)
        ->and($conn->isConnected())->toBeFalse();

    $conn->connect('wss://x/realtime/v1/websocket?apikey=k', ['apikey' => 'k']);
    expect($conn->isConnected())->toBeTrue()
        ->and($conn->connectedUrl)->toBe('wss://x/realtime/v1/websocket?apikey=k')
        ->and($conn->connectedHeaders)->toBe(['apikey' => 'k']);

    $conn->send('frame-1');
    expect($conn->sent)->toBe(['frame-1']);

    $conn->queue('in-1');
    expect($conn->receive(0.0))->toBe('in-1')
        ->and($conn->receive(0.0))->toBeNull();

    $conn->close();
    expect($conn->isConnected())->toBeFalse();
});

test('mock factory returns the injected connection', function () {
    $conn = new MockWebSocketConnection();
    $factory = new MockWebSocketConnectionFactory($conn);
    expect($factory)->toBeInstanceOf(WebSocketConnectionFactory::class)
        ->and($factory->create())->toBe($conn);
});
