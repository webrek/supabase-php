<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Realtime\RealtimeClient;
use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;
use Supabase\Tests\Support\MockWebSocketConnection;

/** A factory that yields a fresh connection each create(), recording how many. */
final class CountingFactory implements WebSocketConnectionFactory
{
    public int $created = 0;

    /** @var list<MockWebSocketConnection> */
    public array $conns = [];

    public function create(): WebSocketConnection
    {
        $this->created++;
        $c = new MockWebSocketConnection();
        $this->conns[] = $c;

        return $c;
    }
}

test('run reconnects and re-subscribes channels after a drop when auto-reconnect is on', function () {
    $factory = new CountingFactory();
    $rt = new RealtimeClient($factory, 'https://demo.supabase.co', 'ANON', 30.0, true, 0.0, 0.0);
    $rt->connect();
    $rt->channel('room1')->onPostgresChanges('*', 'public', 't', null, fn ($c) => null)->subscribe();

    // First connection: drop it so run() must reconnect.
    $factory->conns[0]->connected = false;

    $rt->run(0.0); // one managed iteration: detects drop, reconnects, re-subscribes, then maxSeconds=0 stops

    expect($factory->created)->toBeGreaterThanOrEqual(2);
    // The new connection received a phx_join (re-subscribe).
    $resub = array_filter($factory->conns[1]->sent, fn (string $f) => str_contains($f, 'phx_join'));
    expect($resub)->not->toBeEmpty();
});
