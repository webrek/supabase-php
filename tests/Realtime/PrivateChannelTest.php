<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Realtime\RealtimeClient;
use Supabase\Realtime\Serializer;
use Supabase\Tests\Support\MockClient;
use Supabase\Tests\Support\MockWebSocketConnection;
use Supabase\Tests\Support\MockWebSocketConnectionFactory;

function privateRealtime(MockWebSocketConnection $conn, ?string $accessToken = null): RealtimeClient
{
    return new RealtimeClient(
        new MockWebSocketConnectionFactory($conn),
        'https://demo.supabase.co',
        'ANON',
        accessToken: $accessToken,
    );
}

/**
 * @return array{joinRef: ?string, ref: ?string, topic: string, event: string, payload: array<mixed>}
 */
function frameAt(MockWebSocketConnection $conn, int $index): array
{
    return (new Serializer())->decode($conn->sent[$index]);
}

/**
 * @return array<mixed> the `config` object of the phx_join frame at $index
 */
function joinConfigAt(MockWebSocketConnection $conn, int $index): array
{
    $config = frameAt($conn, $index)['payload']['config'] ?? null;
    \assert(is_array($config));

    return $config;
}

test('a channel created with private: true joins with private=true in its config', function () {
    $conn = new MockWebSocketConnection();
    $rt = privateRealtime($conn);
    $rt->connect();

    $private = $rt->channel('secret', ['private' => true]);
    $private->subscribe();
    $rt->channel('open')->subscribe();

    expect($private->isPrivate())->toBeTrue()
        ->and(joinConfigAt($conn, 0)['private'])->toBeTrue()
        ->and(joinConfigAt($conn, 1)['private'])->toBeFalse();
});

test('the client access token is carried in the join payload', function () {
    $conn = new MockWebSocketConnection();
    $rt = privateRealtime($conn, 'USER_JWT');
    $rt->connect();

    $rt->channel('room-1', ['private' => true])->subscribe();

    expect(frameAt($conn, 0)['payload']['access_token'])->toBe('USER_JWT');
});

test('an explicit access_token param wins over the client token, and no token means no claim', function () {
    $conn = new MockWebSocketConnection();
    $rt = privateRealtime($conn, 'USER_JWT');
    $rt->connect();
    $rt->channel('room-1', ['access_token' => 'OTHER'])->subscribe();

    $anon = new MockWebSocketConnection();
    $rtAnon = privateRealtime($anon);
    $rtAnon->connect();
    $rtAnon->channel('room-1')->subscribe();

    expect(frameAt($conn, 0)['payload']['access_token'])->toBe('OTHER')
        ->and(array_key_exists('access_token', frameAt($anon, 0)['payload']))->toBeFalse();
});

test('setAuth() pushes access_token to joined channels and is used by later joins and rejoins', function () {
    $conn = new MockWebSocketConnection();
    $rt = privateRealtime($conn, 'OLD');
    $rt->connect();
    $room = $rt->channel('room-1', ['private' => true])->subscribe();
    $conn->queue('{"topic":"realtime:room-1","event":"phx_reply","payload":{"status":"ok","response":{}},"ref":"1","join_ref":"1"}');
    $rt->poll();
    expect($room->state())->toBe('joined');

    $rt->setAuth('NEW');

    $push = frameAt($conn, 1);
    expect($push['topic'])->toBe('realtime:room-1')
        ->and($push['event'])->toBe('access_token')
        ->and($push['payload'])->toBe(['access_token' => 'NEW'])
        ->and($push['joinRef'])->toBe('1');

    $rt->channel('room-2')->subscribe();
    $room->rejoin();

    expect(frameAt($conn, 2)['payload']['access_token'])->toBe('NEW')
        ->and(frameAt($conn, 3)['payload']['access_token'])->toBe('NEW');
});

test('setAuth() does not push to channels that are not joined yet', function () {
    $conn = new MockWebSocketConnection();
    $rt = privateRealtime($conn, 'OLD');
    $rt->connect();
    $rt->channel('room-1')->subscribe(); // joining: no reply received

    $rt->setAuth('NEW');

    expect($conn->sent)->toHaveCount(1);
});

test('Client::withAccessToken() propagates the user token to Realtime joins', function () {
    $conn = new MockWebSocketConnection();
    $client = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: new MockClient(),
        webSocketFactory: new MockWebSocketConnectionFactory($conn),
    ));

    $rt = $client->withAccessToken('USER_JWT')->realtime();
    $rt->connect();
    $rt->channel('room-1', ['private' => true])->subscribe();

    expect(frameAt($conn, 0)['payload']['access_token'])->toBe('USER_JWT')
        ->and(joinConfigAt($conn, 0)['private'])->toBeTrue()
        ->and($rt->__debugInfo()['accessToken'])->toBe('***redacted***');
});
