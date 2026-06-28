<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Exception\RealtimeException;
use Supabase\Realtime\RealtimeClient;
use Supabase\Realtime\Serializer;
use Supabase\Tests\Support\MockWebSocketConnection;
use Supabase\Tests\Support\MockWebSocketConnectionFactory;

function realtimeClient(MockWebSocketConnection $conn, float $heartbeat = 30.0): RealtimeClient
{
    return new RealtimeClient(
        new MockWebSocketConnectionFactory($conn),
        'https://demo.supabase.co',
        'ANON-KEY',
        $heartbeat,
    );
}

test('buildUrl derives a wss endpoint with apikey and vsn', function () {
    $rt = realtimeClient(new MockWebSocketConnection());
    expect($rt->buildUrl())->toBe('wss://demo.supabase.co/realtime/v1/websocket?apikey=ANON-KEY&vsn=1.0.0');
});

test('connect opens the connection via the factory at the built url', function () {
    $conn = new MockWebSocketConnection();
    realtimeClient($conn)->connect();

    expect($conn->isConnected())->toBeTrue()
        ->and($conn->connectedUrl)->toBe('wss://demo.supabase.co/realtime/v1/websocket?apikey=ANON-KEY&vsn=1.0.0')
        ->and($conn->connectedHeaders)->toBe(['apikey' => 'ANON-KEY']);
});

test('poll before connect throws RealtimeException', function () {
    expect(fn () => realtimeClient(new MockWebSocketConnection())->poll())->toThrow(RealtimeException::class);
});

test('subscribe sends phx_join with join_ref equal to ref', function () {
    $conn = new MockWebSocketConnection();
    $rt = realtimeClient($conn);
    $rt->connect();
    $rt->channel('room1')->onPostgresChanges('*', 'public', 'messages', null, fn ($p) => null)->subscribe();

    expect($conn->sent)->toHaveCount(1);
    $frame = (new Serializer())->decode($conn->sent[0]);
    expect($frame['topic'])->toBe('realtime:room1')
        ->and($frame['event'])->toBe('phx_join')
        ->and($frame['joinRef'])->toBe($frame['ref']); // join_ref === ref for the join push
});

test('poll routes an incoming postgres_changes frame to the channel callback', function () {
    $conn = new MockWebSocketConnection();
    $rt = realtimeClient($conn);
    $rt->connect();
    $received = [];
    $rt->channel('room1')
        ->onPostgresChanges('*', 'public', 'messages', null, function (array $p) use (&$received): void {
            $received[] = $p;
        })
        ->subscribe();

    // Server confirms the subscription and assigns id 5, then sends a change.
    $conn->queue('["1","2","realtime:room1","phx_reply",{"status":"ok","response":{"postgres_changes":[{"id":5}]}}]');
    $conn->queue('[null,null,"realtime:room1","postgres_changes",{"ids":[5],"data":{"new":{"id":9}}}]');

    $rt->poll(); // reply
    $rt->poll(); // change

    expect($received)->toHaveCount(1)
        ->and($received[0]['new'])->toBe(['id' => 9]);
});

test('heartbeat frame is sent once the interval elapses', function () {
    $conn = new MockWebSocketConnection();
    $rt = realtimeClient($conn, 0.0); // interval 0 => heartbeat on first poll
    $rt->connect();

    $rt->poll();

    expect($conn->sent)->toHaveCount(1);
    $frame = (new Serializer())->decode($conn->sent[0]);
    expect($frame['topic'])->toBe('phoenix')->and($frame['event'])->toBe('heartbeat');
});

test('run stops after maxSeconds and channel is memoized', function () {
    $conn = new MockWebSocketConnection();
    $rt = realtimeClient($conn);
    $rt->connect();

    $rt->run(0.0); // one iteration then stop on elapsed

    expect($rt->channel('room1'))->toBe($rt->channel('room1'));
});

test('disconnect closes the connection', function () {
    $conn = new MockWebSocketConnection();
    $rt = realtimeClient($conn);
    $rt->connect();
    $rt->disconnect();

    expect($conn->isConnected())->toBeFalse();
});

test('poll throws RealtimeException when connection drops after connect', function () {
    $conn = new MockWebSocketConnection();
    $rt = realtimeClient($conn);
    $rt->connect();
    $conn->connected = false;

    expect(fn () => $rt->poll())->toThrow(RealtimeException::class);
});

test('stop() breaks the run loop when called from a channel callback', function () {
    $conn = new MockWebSocketConnection();
    $rt = realtimeClient($conn);
    $rt->connect();

    $stopCalled = false;
    $rt->channel('room1')
        ->onPostgresChanges('*', 'public', 'messages', null, function (array $p) use ($rt, &$stopCalled): void {
            $rt->stop();
            $stopCalled = true;
        })
        ->subscribe();

    // Server confirms the subscription with id 5, then sends a change that triggers the callback.
    $conn->queue('["1","2","realtime:room1","phx_reply",{"status":"ok","response":{"postgres_changes":[{"id":5}]}}]');
    $conn->queue('[null,null,"realtime:room1","postgres_changes",{"ids":[5],"data":{"new":{"id":1}}}]');

    $rt->run(5.0); // callback calls stop(); must return well before the 5s budget

    expect($stopCalled)->toBeTrue();
});
