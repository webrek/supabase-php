<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Exception\RealtimeException;
use Supabase\Realtime\Serializer;

test('encode produces the Phoenix array with join_ref, ref, topic, event, payload', function () {
    $s = new Serializer();
    $json = $s->encode('1', '2', 'realtime:room1', 'phx_join', ['config' => ['private' => false]]);

    expect($json)->toBe('["1","2","realtime:room1","phx_join",{"config":{"private":false}}]');
});

test('encode renders an empty payload as a JSON object', function () {
    $s = new Serializer();
    expect($s->encode(null, '7', 'phoenix', 'heartbeat', []))
        ->toBe('[null,"7","phoenix","heartbeat",{}]');
});

test('decode round-trips a Phoenix array message', function () {
    $s = new Serializer();
    $msg = $s->decode('["1","2","realtime:room1","phx_reply",{"status":"ok"}]');

    expect($msg)->toBe([
        'joinRef' => '1',
        'ref' => '2',
        'topic' => 'realtime:room1',
        'event' => 'phx_reply',
        'payload' => ['status' => 'ok'],
    ]);
});

test('decode rejects malformed json', function () {
    expect(fn () => (new Serializer())->decode('{not json'))
        ->toThrow(RealtimeException::class);
});

test('decode rejects a non-5-element message', function () {
    expect(fn () => (new Serializer())->decode('["a","b"]'))
        ->toThrow(RealtimeException::class);
});
