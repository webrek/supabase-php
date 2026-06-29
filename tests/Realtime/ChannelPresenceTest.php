<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Realtime\Channel;

/**
 * @param list<array{event:string,payload:array<string,array<string,mixed>>,isJoin:bool}> $calls
 * @param array<string, mixed> $params
 */
function presenceChannel(array &$calls, array $params = []): Channel
{
    $pusher = function (string $event, array $payload, bool $isJoin) use (&$calls): void {
        $calls[] = ['event' => $event, 'payload' => $payload, 'isJoin' => $isJoin];
    };

    return new Channel('realtime:room1', $pusher, $params);
}

test('presence_state and presence_diff update presenceState and fire onSync', function () {
    $calls = [];
    $ch = presenceChannel($calls);
    $syncs = 0;
    $ch->onPresenceSync(function () use (&$syncs): void {
        $syncs++;
    });

    $ch->handleMessage('presence_state', ['u1' => ['metas' => [['phx_ref' => 'r1', 'name' => 'ada']]]], null);
    expect($ch->presenceState())->toBe(['u1' => [['presence_ref' => 'r1', 'name' => 'ada']]])
        ->and($syncs)->toBe(1);

    $ch->handleMessage('presence_diff', ['joins' => [], 'leaves' => ['u1' => ['metas' => [['phx_ref' => 'r1']]]]], null);
    expect($ch->presenceState())->toBe([])->and($syncs)->toBe(2);
});

test('onPresenceJoin and onPresenceLeave fire with key and presences', function () {
    $calls = [];
    $ch = presenceChannel($calls);
    $joined = null;
    $ch->onPresenceJoin(function (string $key, array $cur, array $new) use (&$joined): void {
        $joined = [$key, $new];
    });
    $ch->handleMessage('presence_state', ['u1' => ['metas' => [['phx_ref' => 'r1']]]], null);
    expect($joined)->toBe(['u1', [['presence_ref' => 'r1']]]);
});

test('track pushes a presence/track frame; untrack pushes presence/untrack', function () {
    $calls = [];
    $ch = presenceChannel($calls);
    $ch->track(['online_at' => 'now']);
    $ch->untrack();

    expect($calls[0]['event'])->toBe('presence')
        ->and($calls[0]['isJoin'])->toBeFalse()
        ->and($calls[0]['payload'])->toBe(['type' => 'presence', 'event' => 'track', 'payload' => ['online_at' => 'now']])
        ->and($calls[1]['payload'])->toBe(['type' => 'presence', 'event' => 'untrack']);
});

test('join config carries presence key and enables presence when bindings exist', function () {
    $calls = [];
    $ch = presenceChannel($calls, ['presence_key' => 'user-7']);
    $ch->onPresenceSync(fn () => null)->subscribe();

    $cfg = $calls[0]['payload']['config'];
    expect($cfg['presence'])->toBe(['key' => 'user-7', 'enabled' => true]);
});

test('presence is disabled in join config when no presence is used', function () {
    $calls = [];
    $ch = presenceChannel($calls);
    $ch->subscribe();
    expect($calls[0]['payload']['config']['presence'])->toBe(['key' => '', 'enabled' => false]);
});
