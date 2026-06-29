<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Exception\RealtimeException;
use Supabase\Realtime\PhrityWebSocketConnection;
use Supabase\Realtime\PhrityWebSocketConnectionFactory;
use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;
use Supabase\Tests\Support\WebSocketClientSpy;
use WebSocket\Exception\ConnectionFailureException;
use WebSocket\Message\Close;
use WebSocket\Message\Ping;
use WebSocket\Message\Text;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a PhrityWebSocketConnection pre-wired to the given spy, and
 * optionally already connected at $url with $headers.
 *
 * @param array<string, string> $headers
 */
function connWithSpy(
    WebSocketClientSpy $spy,
    string $url = 'wss://project.supabase.co/realtime/v1/websocket?apikey=k',
    array $headers = ['apikey' => 'k'],
): PhrityWebSocketConnection {
    $conn = new PhrityWebSocketConnection(
        fn (string $u, array $h) => $spy
    );
    $conn->connect($url, $headers);
    return $conn;
}

// ---------------------------------------------------------------------------
// Interface compliance
// ---------------------------------------------------------------------------

test('PhrityWebSocketConnection implements WebSocketConnection', function () {
    $conn = new PhrityWebSocketConnection(fn ($u, $h) => new WebSocketClientSpy());
    expect($conn)->toBeInstanceOf(WebSocketConnection::class);
});

test('PhrityWebSocketConnectionFactory implements WebSocketConnectionFactory', function () {
    expect(new PhrityWebSocketConnectionFactory())->toBeInstanceOf(WebSocketConnectionFactory::class);
});

test('PhrityWebSocketConnectionFactory::create returns a PhrityWebSocketConnection', function () {
    expect((new PhrityWebSocketConnectionFactory())->create())
        ->toBeInstanceOf(PhrityWebSocketConnection::class);
});

// ---------------------------------------------------------------------------
// connect() — passes url and headers through the factory closure
// ---------------------------------------------------------------------------

test('connect() passes the url and headers to the factory closure', function () {
    $capturedUrl = null;
    $capturedHeaders = null;
    $spy = new WebSocketClientSpy();

    $conn = new PhrityWebSocketConnection(
        function (string $url, array $headers) use ($spy, &$capturedUrl, &$capturedHeaders): WebSocketClientSpy {
            $capturedUrl = $url;
            $capturedHeaders = $headers;
            return $spy;
        }
    );

    $conn->connect('wss://example.supabase.co/realtime', ['apikey' => 'secret']);

    expect($capturedUrl)->toBe('wss://example.supabase.co/realtime')
        ->and($capturedHeaders)->toBe(['apikey' => 'secret']);
});

// ---------------------------------------------------------------------------
// isConnected()
// ---------------------------------------------------------------------------

test('isConnected() returns true when the spy reports connected', function () {
    $spy = new WebSocketClientSpy();
    $conn = connWithSpy($spy);
    expect($conn->isConnected())->toBeTrue();
});

test('isConnected() returns false when the spy reports disconnected', function () {
    $spy = new WebSocketClientSpy();
    $conn = connWithSpy($spy);
    $spy->connected = false;
    expect($conn->isConnected())->toBeFalse();
});

test('isConnected() returns false before connect() is called', function () {
    $conn = new PhrityWebSocketConnection(fn ($u, $h) => new WebSocketClientSpy());
    expect($conn->isConnected())->toBeFalse();
});

// ---------------------------------------------------------------------------
// send() — delegates to phrity text()
// ---------------------------------------------------------------------------

test('send() passes the payload as a Text message to the phrity client', function () {
    $spy = new WebSocketClientSpy();
    $conn = connWithSpy($spy);

    $conn->send('{"event":"heartbeat"}');

    expect($spy->sentMessages)->toHaveCount(1)
        ->and($spy->sentMessages[0])->toBeInstanceOf(Text::class)
        ->and($spy->sentMessages[0]->getContent())->toBe('{"event":"heartbeat"}');
});

// ---------------------------------------------------------------------------
// receive() — happy path: text message returned
// ---------------------------------------------------------------------------

test('receive() returns the content of a Text frame', function () {
    $spy = new WebSocketClientSpy();
    $spy->queueReceive(new Text('{"topic":"room1"}'));
    $conn = connWithSpy($spy);

    expect($conn->receive(1.0))->toBe('{"topic":"room1"}');
});

// ---------------------------------------------------------------------------
// receive() — timeout: returns null
// ---------------------------------------------------------------------------

test('receive() returns null when phrity throws ConnectionTimeoutException', function () {
    $spy = new WebSocketClientSpy();
    // empty queue → spy throws ConnectionTimeoutException
    $conn = connWithSpy($spy);

    expect($conn->receive(0.01))->toBeNull();
});

// ---------------------------------------------------------------------------
// receive() — non-text control frames are skipped
// ---------------------------------------------------------------------------

test('receive() skips a Ping frame and returns null when queue is empty after', function () {
    $spy = new WebSocketClientSpy();
    $spy->queueReceive(new Ping('keepalive'));
    // After the Ping, the queue is empty → ConnectionTimeoutException → null
    $conn = connWithSpy($spy);

    expect($conn->receive(0.01))->toBeNull();
});

test('receive() skips a Ping and then returns the following Text frame', function () {
    $spy = new WebSocketClientSpy();
    $spy->queueReceive(new Ping());
    $spy->queueReceive(new Text('{"event":"phx_reply"}'));
    $conn = connWithSpy($spy);

    expect($conn->receive(1.0))->toBe('{"event":"phx_reply"}');
});

// ---------------------------------------------------------------------------
// receive() — Close frame throws RealtimeException
// ---------------------------------------------------------------------------

test('receive() throws RealtimeException when a Close frame is received', function () {
    $spy = new WebSocketClientSpy();
    $spy->queueReceive(new Close(1001, 'going away'));
    $conn = connWithSpy($spy);

    expect(fn () => $conn->receive(1.0))->toThrow(RealtimeException::class);
});

// ---------------------------------------------------------------------------
// receive() — genuine connection error wrapped as RealtimeException
// ---------------------------------------------------------------------------

test('receive() wraps a non-timeout phrity exception as RealtimeException', function () {
    $spy = new WebSocketClientSpy();
    $spy->queueReceive(new ConnectionFailureException('socket reset'));
    $conn = connWithSpy($spy);

    expect(fn () => $conn->receive(1.0))->toThrow(RealtimeException::class);
});

// ---------------------------------------------------------------------------
// close() — delegates to phrity client
// ---------------------------------------------------------------------------

test('close() sends a Close frame (via send) and disconnects the phrity client', function () {
    $spy = new WebSocketClientSpy();
    $conn = connWithSpy($spy);

    $conn->close(1000, 'done');

    // spy::send() is called by Client::close() which calls send(new Close(...))
    expect($spy->sentMessages)->toHaveCount(1)
        ->and($spy->sentMessages[0])->toBeInstanceOf(Close::class)
        ->and($spy->connected)->toBeFalse(); // disconnect() was called
});

test('close() makes isConnected() return false', function () {
    $spy = new WebSocketClientSpy();
    $conn = connWithSpy($spy);
    $conn->close();
    expect($conn->isConnected())->toBeFalse();
});

test('close() before connect() is a no-op', function () {
    $conn = new PhrityWebSocketConnection(fn ($u, $h) => new WebSocketClientSpy());
    // Must not throw
    $conn->close();
    expect($conn->isConnected())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Error guards
// ---------------------------------------------------------------------------

test('send() before connect() throws RealtimeException', function () {
    $conn = new PhrityWebSocketConnection(fn ($u, $h) => new WebSocketClientSpy());
    expect(fn () => $conn->send('data'))->toThrow(RealtimeException::class);
});

test('receive() before connect() throws RealtimeException', function () {
    $conn = new PhrityWebSocketConnection(fn ($u, $h) => new WebSocketClientSpy());
    expect(fn () => $conn->receive(1.0))->toThrow(RealtimeException::class);
});
