<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Exception\RealtimeException;
use Supabase\Realtime\Serializer;

test('encode produces the Phoenix object with topic, event, payload, ref, join_ref', function () {
    $s = new Serializer();
    $json = $s->encode('1', '2', 'realtime:room1', 'phx_join', ['config' => ['private' => false]]);

    expect($json)->toBe('{"topic":"realtime:room1","event":"phx_join","payload":{"config":{"private":false}},"ref":"2","join_ref":"1"}');
});

test('encode renders an empty payload as a JSON object', function () {
    $s = new Serializer();
    expect($s->encode(null, '7', 'phoenix', 'heartbeat', []))
        ->toBe('{"topic":"phoenix","event":"heartbeat","payload":{},"ref":"7"}');
});

test('decode round-trips a Phoenix object message', function () {
    $s = new Serializer();
    $msg = $s->decode('{"topic":"realtime:room1","event":"phx_reply","payload":{"status":"ok"},"ref":"2","join_ref":"1"}');

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

test('decode rejects a JSON array (v2 format)', function () {
    expect(fn () => (new Serializer())->decode('["a","b"]'))
        ->toThrow(RealtimeException::class);
});
