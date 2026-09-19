<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\SupabaseException;
use Supabase\Realtime\PhrityWebSocketConnectionFactory;
use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;

/** Wraps the phrity adapter to count how many sockets run() opened. */
final class CountingPhrityFactory implements WebSocketConnectionFactory
{
    public int $created = 0;

    private readonly PhrityWebSocketConnectionFactory $inner;

    public function __construct()
    {
        $this->inner = new PhrityWebSocketConnectionFactory();
    }

    public function create(): WebSocketConnection
    {
        $this->created++;

        return $this->inner->create();
    }
}

/**
 * Auto-reconnect against a real outage: the Realtime container is restarted
 * under a subscribed client, and run() must notice the dead socket, back off,
 * reconnect, re-join the channel and deliver again. Needs the docker CLI (the
 * stack itself runs on it); skipped otherwise.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Realtime: run() survives a Realtime server restart — reconnects, re-subscribes and delivers again', function (): void {
    $container = trim((string) shell_exec("docker ps --filter name=supabase_realtime --format '{{.Names}}' 2>/dev/null"));
    if ($container === '') {
        \PHPUnit\Framework\Assert::markTestSkipped('docker CLI or the Realtime container is not available');
    }

    $url = getenv('SUPABASE_URL');
    $key = getenv('SUPABASE_ANON_KEY');
    \assert(is_string($url) && is_string($key));
    $psr17 = new Psr17Factory();
    $sockets = new CountingPhrityFactory();
    $rt = (new Client($url, $key, new ClientOptions(
        httpClient: new GuzzleClient(['allow_redirects' => false, 'timeout' => 10.0]),
        requestFactory: $psr17,
        streamFactory: $psr17,
        webSocketFactory: $sockets,
        realtimeAutoReconnect: true,
        realtimeReconnectBaseDelay: 0.5,
        realtimeReconnectMaxDelay: 2.0,
    )))->realtime();
    $sender = IntegrationSupport::client()->realtime(); // REST broadcasts, no socket

    $inbox = new class () {
        /** @var list<string> */
        public array $markers = [];

        /** @param array<mixed> $message */
        public function capture(array $message): void
        {
            $payload = $message['payload'] ?? null;
            $marker = is_array($payload) ? ($payload['marker'] ?? null) : null;
            if (is_string($marker) && ! in_array($marker, $this->markers, true)) {
                $this->markers[] = $marker;
            }
        }
    };
    $received = static fn (string $marker): bool => in_array($marker, $inbox->markers, true);

    $topic = 'reconnect-' . uniqid();
    $channel = $rt->channel($topic)->onBroadcast('tick', function (array $message) use ($inbox): void {
        $inbox->capture($message);
    });
    $rt->connect();
    $channel->subscribe();
    expect(IntegrationSupport::waitForJoin($rt, $channel))->toBe('joined');

    // Baseline: a broadcast reaches the subscriber over the first socket.
    $deadline = microtime(true) + 20.0;
    $lastSend = 0.0;
    while (microtime(true) < $deadline && ! $received('before')) {
        if (microtime(true) - $lastSend >= 1.0) {
            $sender->broadcast($topic, 'tick', ['marker' => 'before']);
            $lastSend = microtime(true);
        }
        $rt->poll(0.3);
    }
    expect($received('before'))->toBeTrue();

    // Pull the server out from under the client.
    exec('docker restart ' . escapeshellarg($container) . ' >/dev/null 2>&1');

    // Drive run() in slices: it detects the dead socket, backs off, reconnects
    // (retrying while the server boots) and re-joins. REST sends fail while
    // the server is down, so they are best-effort until the channel is back.
    $deadline = microtime(true) + 120.0;
    $lastSend = 0.0;
    while (microtime(true) < $deadline && ! $received('after')) {
        $rt->run(1.0);
        if ($channel->state() === 'joined' && microtime(true) - $lastSend >= 1.0) {
            try {
                $sender->broadcast($topic, 'tick', ['marker' => 'after']);
            } catch (SupabaseException) {
                // Realtime (or Kong in front of it) is still coming back.
            }
            $lastSend = microtime(true);
        }
    }
    $rt->disconnect();

    expect($received('after'))->toBeTrue()
        ->and($sockets->created)->toBeGreaterThanOrEqual(2)
        ->and($inbox->markers)->toBe(['before', 'after']);
});
