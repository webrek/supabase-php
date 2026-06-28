<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Realtime\Channel;

/**
 * Records pusher calls so tests can assert the join payload.
 *
 * @param list<array{event: string, payload: array<string, array<string, array<mixed>>>, isJoin: bool}> $calls
 * @param array<string, mixed> $params
 */
function recordingChannel(array &$calls, array $params = []): Channel
{
    $pusher = function (string $event, array $payload, bool $isJoin) use (&$calls): void {
        $calls[] = ['event' => $event, 'payload' => $payload, 'isJoin' => $isJoin];
    };

    return new Channel('realtime:room1', $pusher, $params);
}

test('subscribe pushes phx_join with the postgres_changes config and enters joining', function () {
    $calls = [];
    $ch = recordingChannel($calls);
    $ch->onPostgresChanges('INSERT', 'public', 'messages', 'id=eq.1', fn ($p) => null)
        ->subscribe();

    expect($ch->state())->toBe('joining')
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['event'])->toBe('phx_join')
        ->and($calls[0]['isJoin'])->toBeTrue()
        ->and($calls[0]['payload']['config']['postgres_changes'])->toBe([
            ['event' => 'INSERT', 'schema' => 'public', 'table' => 'messages', 'filter' => 'id=eq.1'],
        ])
        ->and($calls[0]['payload']['config']['private'])->toBeFalse();
});

test('join config omits filter when null and includes access_token from params', function () {
    $calls = [];
    $ch = recordingChannel($calls, ['access_token' => 'jwt-1']);
    $ch->onPostgresChanges('*', 'public', 'rooms', null, fn ($p) => null)->subscribe();

    expect($calls[0]['payload']['config']['postgres_changes'][0])
        ->toBe(['event' => '*', 'schema' => 'public', 'table' => 'rooms'])
        ->and($calls[0]['payload']['access_token'])->toBe('jwt-1');
});

test('phx_reply ok maps server ids and enters joined; status callback fires', function () {
    $calls = [];
    $status = [];
    $ch = recordingChannel($calls);
    $received = [];
    $ch->onPostgresChanges('*', 'public', 'messages', null, function (array $p) use (&$received): void {
        $received[] = $p;
    })->subscribe(function (string $s) use (&$status): void {
        $status[] = $s;
    });

    $ch->handleMessage('phx_reply', [
        'status' => 'ok',
        'response' => ['postgres_changes' => [['id' => 42, 'event' => '*', 'schema' => 'public', 'table' => 'messages']]],
    ], '2');

    expect($ch->state())->toBe('joined')
        ->and($status)->toBe(['subscribed']);

    // A change carrying id 42 reaches the matching binding's callback.
    $ch->handleMessage('postgres_changes', [
        'ids' => [42],
        'data' => ['table' => 'messages', 'eventType' => 'INSERT', 'new' => ['id' => 7]],
    ], null);

    expect($received)->toHaveCount(1)
        ->and($received[0]['new'])->toBe(['id' => 7]);
});

test('postgres_changes with a non-matching id does not invoke the callback', function () {
    $calls = [];
    $ch = recordingChannel($calls);
    $hits = 0;
    $ch->onPostgresChanges('*', 'public', 'messages', null, function ($p) use (&$hits): void {
        $hits++;
    })->subscribe();
    $ch->handleMessage('phx_reply', ['status' => 'ok', 'response' => ['postgres_changes' => [['id' => 1]]]], '2');

    $ch->handleMessage('postgres_changes', ['ids' => [999], 'data' => []], null);

    expect($hits)->toBe(0);
});

test('phx_reply error and phx_error move to errored', function () {
    $calls = [];
    $status = [];
    $ch = recordingChannel($calls);
    $ch->subscribe(function (string $s) use (&$status): void {
        $status[] = $s;
    });
    $ch->handleMessage('phx_reply', ['status' => 'error', 'response' => ['reason' => 'nope']], '2');
    expect($ch->state())->toBe('errored')->and($status)->toBe(['error']);
});

test('presence and unknown events are ignored without error', function () {
    $calls = [];
    $ch = recordingChannel($calls);
    $ch->handleMessage('presence_state', ['foo' => 'bar'], null);
    $ch->handleMessage('something_else', [], null);
    expect($ch->state())->toBe('closed');
});
