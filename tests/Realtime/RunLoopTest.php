<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Exception\RealtimeException;
use Supabase\Realtime\RealtimeClient;
use Supabase\Realtime\Serializer;
use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;

/*
 * The run() loop with a fake clock and a recorded sleeper: back-off timing,
 * reconnect attempts, maxSeconds, stop(), and the guard that lets run() be
 * driven in slices after a drop.
 */

/** A connection whose receive() follows a script: a frame, null (idle), a Throwable (drop) or a closure. */
final class ScriptedConnection implements WebSocketConnection
{
    public int $connectAttempts = 0;

    /** connect() throws this many times before succeeding (the server is "down"). */
    public int $failConnects = 0;

    public bool $connected = false;

    /** @var list<string> */
    public array $sent = [];

    /** @var list<string|\Throwable|\Closure|null> */
    private array $script = [];

    public function queue(string|\Throwable|\Closure|null $item): void
    {
        $this->script[] = $item;
    }

    public function connect(string $url, array $headers = []): void
    {
        $this->connectAttempts++;
        if ($this->failConnects > 0) {
            $this->failConnects--;
            throw new \RuntimeException('connection refused');
        }
        $this->connected = true;
    }

    public function send(string $data): void
    {
        $this->sent[] = $data;
    }

    public function receive(float $timeoutSeconds): ?string
    {
        $item = array_shift($this->script);
        if ($item instanceof \Throwable) {
            $this->connected = false;
            throw $item;
        }
        if ($item instanceof \Closure) {
            $result = $item($this);

            return is_string($result) ? $result : null;
        }

        return $item;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}

/** Hands out the same scripted connection every time so its script spans reconnects. */
final class ScriptedFactory implements WebSocketConnectionFactory
{
    public int $created = 0;

    public function __construct(public readonly ScriptedConnection $conn)
    {
    }

    public function create(): WebSocketConnection
    {
        $this->created++;

        return $this->conn;
    }
}

/**
 * @return array{rt: RealtimeClient, conn: ScriptedConnection, factory: ScriptedFactory, sleeps: \ArrayObject<int, float>, clock: \ArrayObject<int, float>}
 */
function runLoop(bool $autoReconnect, float $base = 1.0, float $max = 30.0, float $heartbeat = 30.0): array
{
    $conn = new ScriptedConnection();
    $factory = new ScriptedFactory($conn);
    /** @var \ArrayObject<int, float> $clock */
    $clock = new \ArrayObject([0.0]);
    /** @var \ArrayObject<int, float> $sleeps */
    $sleeps = new \ArrayObject();

    $rt = new RealtimeClient(
        $factory,
        'https://demo.supabase.co',
        'ANON',
        heartbeatInterval: $heartbeat,
        autoReconnect: $autoReconnect,
        reconnectBaseDelay: $base,
        reconnectMaxDelay: $max,
        clock: static fn (): float => $clock[0] ?? 0.0,
        sleeper: static function (float $seconds) use ($sleeps, $clock): void {
            $sleeps[] = $seconds;
            $clock[0] += $seconds; // sleeping is the only thing that advances the fake clock
        },
    );

    return ['rt' => $rt, 'conn' => $conn, 'factory' => $factory, 'sleeps' => $sleeps, 'clock' => $clock];
}

test('back-off doubles up to the cap, resets after a successful reconnect, and channels are re-joined', function () {
    ['rt' => $rt, 'conn' => $conn, 'factory' => $factory, 'sleeps' => $sleeps] = runLoop(true, base: 1.0, max: 4.0);
    $rt->connect();
    $rt->channel('room1')->onBroadcast('*', fn () => null)->subscribe();

    $conn->queue(new \RuntimeException('socket reset'));   // first poll drops the connection
    $conn->failConnects = 3;                                // the server stays down for three attempts

    $rt->run(11.0);   // sleeps 1 + 2 + 4 + 4 = 11 → reconnects, polls once, then the budget is spent

    expect($sleeps->getArrayCopy())->toBe([1.0, 2.0, 4.0, 4.0])
        ->and($conn->connectAttempts)->toBe(5)      // connect() + 4 reconnect attempts
        ->and($factory->created)->toBe(5)
        ->and($conn->isConnected())->toBeTrue();
    $joins = array_filter($conn->sent, fn (string $f) => str_contains($f, 'phx_join'));
    expect($joins)->toHaveCount(2);              // subscribe() + the re-subscribe after reconnecting

    // A second drop starts again from the base delay, not from where the cap left off.
    $conn->queue(new \RuntimeException('socket reset again'));
    $rt->run(1.0);

    expect($sleeps->getArrayCopy())->toBe([1.0, 2.0, 4.0, 4.0, 1.0]);
});

test('maxSeconds ends run() while the server keeps refusing, and a later run() resumes reconnecting', function () {
    ['rt' => $rt, 'conn' => $conn, 'sleeps' => $sleeps, 'clock' => $clock] = runLoop(true, base: 1.0, max: 8.0);
    $rt->connect();
    $conn->queue(new \RuntimeException('gone'));
    $conn->failConnects = 100;

    $rt->run(5.0);   // 1 + 2 = 3 < 5 → keep trying; 3 + 4 = 7 ≥ 5 → give up for now

    expect($sleeps->getArrayCopy())->toBe([1.0, 2.0, 4.0])
        ->and($conn->isConnected())->toBeFalse();

    // The connection is gone (null) but connect() once succeeded: run() must
    // pick the reconnect loop back up instead of demanding connect() again.
    $conn->failConnects = 0;
    $start = $clock[0];
    $rt->run(1.0);

    expect($conn->isConnected())->toBeTrue()
        ->and($sleeps->getArrayCopy())->toBe([1.0, 2.0, 4.0, 1.0])
        ->and($clock[0] - $start)->toBe(1.0);
});

test('without auto-reconnect a receive error propagates and a silent drop ends run() quietly', function () {
    ['rt' => $rt, 'conn' => $conn, 'sleeps' => $sleeps] = runLoop(false);
    $rt->connect();
    $conn->queue(new \RuntimeException('socket reset'));

    expect(fn () => $rt->run())->toThrow(RealtimeException::class, 'socket reset');

    $rt->connect();
    $conn->queue(static function (ScriptedConnection $c): null {
        $c->connected = false; // the socket closed under us without an error

        return null;
    });
    $rt->run();

    expect($conn->isConnected())->toBeFalse()
        ->and($sleeps->getArrayCopy())->toBe([]);   // never backs off
});

test('run() refuses to start before connect() and after disconnect(), even with auto-reconnect', function () {
    ['rt' => $rt] = runLoop(true);

    expect(fn () => $rt->run(0.0))->toThrow(RealtimeException::class, 'connect() first');

    $rt->connect();
    $rt->run(0.0);
    $rt->disconnect();

    expect(fn () => $rt->run(0.0))->toThrow(RealtimeException::class, 'connect() first');
});

test('stop() called from a callback ends run() without a time budget', function () {
    ['rt' => $rt, 'conn' => $conn] = runLoop(false);
    $hits = 0;
    $rt->connect();
    $rt->channel('room1')->onBroadcast('tick', function () use ($rt, &$hits): void {
        $hits++;
        $rt->stop();
    })->subscribe();
    $conn->queue('{"topic":"realtime:room1","event":"broadcast","payload":{"type":"broadcast","event":"tick","payload":{}},"ref":null}');
    $conn->queue('{"topic":"realtime:room1","event":"broadcast","payload":{"type":"broadcast","event":"tick","payload":{}},"ref":null}');

    $rt->run();

    expect($hits)->toBe(1);
});

test('heartbeats follow the injected clock', function () {
    ['rt' => $rt, 'conn' => $conn, 'clock' => $clock] = runLoop(false, heartbeat: 5.0);
    $rt->connect();

    $rt->poll();
    $clock[0] = 4.9;
    $rt->poll();
    expect($conn->sent)->toBe([]);

    $clock[0] = 5.0;
    $rt->poll();
    expect($conn->sent)->toHaveCount(1);
    $frame = (new Serializer())->decode($conn->sent[0]);
    expect($frame['topic'])->toBe('phoenix')
        ->and($frame['event'])->toBe('heartbeat');
});
