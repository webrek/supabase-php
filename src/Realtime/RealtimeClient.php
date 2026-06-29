<?php

declare(strict_types=1);

namespace Supabase\Realtime;

use Supabase\Exception\RealtimeException;
use Supabase\Exception\SupabaseException;
use Supabase\Http\HeaderRedaction;

/**
 * Realtime client over the Phoenix channels protocol. Owns the WebSocket
 * connection lifecycle, ref / join_ref allocation, heartbeats, and routing of
 * incoming frames to Channel instances. Synchronous: the consumer drives it
 * with poll() or the blocking run() loop (suited to CLI / worker contexts).
 */
final class RealtimeClient
{
    private const VSN = '1.0.0';

    private ?WebSocketConnection $conn = null;

    private int $refCounter = 0;

    private float $lastHeartbeat = 0.0;

    private bool $running = false;

    /** @var array<string, Channel> topic => Channel */
    private array $channels = [];

    /** @var array<string, string> topic => current join ref */
    private array $joinRefs = [];

    private readonly Serializer $serializer;

    public function __construct(
        private readonly WebSocketConnectionFactory $factory,
        private readonly string $url,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly float $heartbeatInterval = 30.0,
        private readonly bool $autoReconnect = false,
        private readonly float $reconnectBaseDelay = 1.0,
        private readonly float $reconnectMaxDelay = 30.0,
    ) {
        $this->serializer = new Serializer();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function channel(string $name, array $params = []): Channel
    {
        $topic = str_starts_with($name, 'realtime:') ? $name : 'realtime:' . $name;
        if (isset($this->channels[$topic])) {
            return $this->channels[$topic];
        }

        $pusher = function (string $event, array $payload, bool $isJoin) use ($topic): void {
            $ref = $this->nextRef();
            if ($isJoin) {
                $this->joinRefs[$topic] = $ref;
            }
            $this->sendFrame($this->joinRefs[$topic] ?? null, $ref, $topic, $event, $payload);
        };

        return $this->channels[$topic] = new Channel($topic, $pusher, $params);
    }

    public function connect(): void
    {
        if ($this->conn !== null && $this->conn->isConnected()) {
            return;
        }

        if ($this->conn !== null) {
            try {
                $this->conn->close();
            } catch (\Throwable) {
                // best-effort close of a stale connection
            }
        }

        $conn = $this->factory->create();
        try {
            $conn->connect($this->buildUrl(), ['apikey' => $this->apiKey]);
        } catch (\Throwable $e) {
            throw $this->wrap($e, 'Failed to open Realtime connection');
        }

        $this->conn = $conn;
        $this->lastHeartbeat = $this->now();
    }

    public function poll(float $timeout = 0.0): void
    {
        $conn = $this->requireConn();
        try {
            $raw = $conn->receive($timeout);
        } catch (\Throwable $e) {
            throw $this->wrap($e, 'Failed to receive Realtime message');
        }

        if ($raw !== null) {
            $this->dispatch($raw);
        }

        $this->maybeHeartbeat();
    }

    public function run(?float $maxSeconds = null): void
    {
        if ($this->conn === null || (! $this->autoReconnect && ! $this->conn->isConnected())) {
            throw new RealtimeException('Realtime is not connected. Call connect() first.');
        }

        $this->running = true;
        $start = $this->now();
        $delay = $this->reconnectBaseDelay;
        $pollTimeout = min(1.0, $maxSeconds ?? 1.0);

        while ($this->running) {
            if (! ($this->conn?->isConnected() ?? false)) {
                if (! $this->autoReconnect) {
                    break;
                }

                $this->sleepSeconds($delay);
                $delay = min($delay * 2.0, $this->reconnectMaxDelay);

                try {
                    $this->reconnect();
                    $delay = $this->reconnectBaseDelay;
                } catch (\Throwable) {
                    if ($maxSeconds !== null && ($this->now() - $start) >= $maxSeconds) {
                        break;
                    }

                    continue; // keep backing off
                }
            }

            try {
                $this->poll($pollTimeout);
            } catch (\Throwable $e) {
                if (! $this->autoReconnect) {
                    throw $e;
                }

                // drop will be detected at loop top
                $this->conn = null;
            }

            if ($maxSeconds !== null && ($this->now() - $start) >= $maxSeconds) {
                break;
            }
        }

        $this->running = false;
    }

    private function reconnect(): void
    {
        if ($this->conn !== null) {
            try {
                $this->conn->close();
            } catch (\Throwable) {
            }
        }

        $conn = $this->factory->create();
        $conn->connect($this->buildUrl(), ['apikey' => $this->apiKey]);
        $this->conn = $conn;
        $this->lastHeartbeat = $this->now();
        $this->resubscribe();
    }

    private function resubscribe(): void
    {
        foreach ($this->channels as $channel) {
            if (in_array($channel->state(), ['joined', 'joining'], true)) {
                $channel->rejoin();
            }
        }
    }

    private function sleepSeconds(float $seconds): void
    {
        if ($seconds > 0.0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function disconnect(): void
    {
        if ($this->conn !== null) {
            try {
                $this->conn->close();
            } catch (\Throwable) {
                // best-effort close
            }
            $this->conn = null;
        }
        $this->running = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'url' => $this->url,
            'apiKey' => HeaderRedaction::REDACTED,
            'connected' => $this->conn?->isConnected() ?? false,
            'channels' => array_keys($this->channels),
            'heartbeatInterval' => $this->heartbeatInterval,
            'autoReconnect' => $this->autoReconnect,
            'reconnectBaseDelay' => $this->reconnectBaseDelay,
            'reconnectMaxDelay' => $this->reconnectMaxDelay,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('RealtimeClient must not be serialized; it holds credentials and a live connection.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('RealtimeClient must not be unserialized; it holds credentials and a live connection.');
    }

    public function buildUrl(): string
    {
        $base = rtrim($this->url, '/');
        $ws = preg_replace('#^http#i', 'ws', $base) ?? $base;
        $query = http_build_query(
            ['apikey' => $this->apiKey, 'vsn' => self::VSN],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $ws . '/realtime/v1/websocket?' . $query;
    }

    /**
     * @param array<mixed> $payload
     */
    private function sendFrame(?string $joinRef, string $ref, string $topic, string $event, array $payload): void
    {
        $conn = $this->requireConn();
        $frame = $this->serializer->encode($joinRef, $ref, $topic, $event, $payload);
        try {
            $conn->send($frame);
        } catch (\Throwable $e) {
            throw $this->wrap($e, 'Failed to send Realtime message');
        }
    }

    private function dispatch(string $raw): void
    {
        $msg = $this->serializer->decode($raw);
        if ($msg['topic'] === 'phoenix') {
            return; // heartbeat reply
        }
        $channel = $this->channels[$msg['topic']] ?? null;
        $channel?->handleMessage($msg['event'], $msg['payload'], $msg['ref']);
    }

    private function maybeHeartbeat(): void
    {
        if (($this->now() - $this->lastHeartbeat) >= $this->heartbeatInterval) {
            $this->sendFrame(null, $this->nextRef(), 'phoenix', 'heartbeat', []);
            $this->lastHeartbeat = $this->now();
        }
    }

    private function nextRef(): string
    {
        return (string) (++$this->refCounter);
    }

    private function now(): float
    {
        return microtime(true);
    }

    private function requireConn(): WebSocketConnection
    {
        if ($this->conn === null || !$this->conn->isConnected()) {
            throw new RealtimeException('Realtime is not connected. Call connect() first.');
        }

        return $this->conn;
    }

    private function wrap(\Throwable $e, string $context): SupabaseException
    {
        if ($e instanceof SupabaseException) {
            return $e;
        }

        return new RealtimeException($context . ': ' . $e->getMessage(), previous: $e);
    }
}
