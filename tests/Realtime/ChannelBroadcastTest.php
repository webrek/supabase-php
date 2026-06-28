<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Realtime\Channel;

/**
 * Records pusher calls so tests can assert outgoing broadcast frames.
 *
 * @param list<array{event: string, payload: array<mixed>, isJoin: bool}> $calls
 */
function broadcastChannel(array &$calls): Channel
{
    $pusher = function (string $event, array $payload, bool $isJoin) use (&$calls): void {
        $calls[] = ['event' => $event, 'payload' => $payload, 'isJoin' => $isJoin];
    };

    return new Channel('realtime:room1', $pusher);
}

test('send pushes a broadcast frame with type, event and payload', function () {
    $calls = [];
    $ch = broadcastChannel($calls);

    $ch->send('cursor', ['x' => 1, 'y' => 2]);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['event'])->toBe('broadcast')
        ->and($calls[0]['isJoin'])->toBeFalse()
        ->and($calls[0]['payload'])->toBe([
            'type' => 'broadcast',
            'event' => 'cursor',
            'payload' => ['x' => 1, 'y' => 2],
        ]);
});

test('incoming broadcast invokes the matching event callback only', function () {
    $calls = [];
    $ch = broadcastChannel($calls);
    $cursor = [];
    $other = 0;
    $ch->onBroadcast('cursor', function (array $p) use (&$cursor): void {
        $cursor[] = $p;
    });
    $ch->onBroadcast('chat', function ($p) use (&$other): void {
        $other++;
    });

    $ch->handleMessage('broadcast', ['type' => 'broadcast', 'event' => 'cursor', 'payload' => ['x' => 5]], null);

    expect($cursor)->toHaveCount(1)
        ->and($cursor[0]['payload'])->toBe(['x' => 5])
        ->and($other)->toBe(0);
});

test('a wildcard broadcast binding receives every event', function () {
    $calls = [];
    $ch = broadcastChannel($calls);
    $seen = [];
    $ch->onBroadcast('*', function (array $p) use (&$seen): void {
        $seen[] = $p['event'] ?? null;
    });

    $ch->handleMessage('broadcast', ['event' => 'a', 'payload' => []], null);
    $ch->handleMessage('broadcast', ['event' => 'b', 'payload' => []], null);

    expect($seen)->toBe(['a', 'b']);
});

test('onBroadcast and onPostgresChanges are chainable and broadcast does not disturb channel state', function () {
    $calls = [];
    $ch = broadcastChannel($calls);
    $result = $ch->onBroadcast('x', fn ($p) => null)
        ->onPostgresChanges('*', 'public', 't', null, fn ($p) => null);

    expect($result)->toBe($ch)
        ->and($ch->state())->toBe('closed');
});
