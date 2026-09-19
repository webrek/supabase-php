<?php

declare(strict_types=1);

namespace Supabase\Realtime;

use Supabase\Exception\RealtimeException;
use Supabase\Exception\SupabaseException;
use Supabase\Http\HeaderRedaction;
use Supabase\Http\Transport;

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

    /** connect() has succeeded at least once; run() refuses to start before that. */
    private bool $connectedOnce = false;

    /**
     * @param WebSocketConnectionFactory|null $factory required for the WebSocket
     *        API (connect / channels); broadcast() over HTTP works without it.
     * @param Transport|null $transport required for broadcast() over HTTP.
     * @param string|null $accessToken user JWT sent with channel joins so
     *        private channels are authorised as that user.
     * @param (\Closure(): float)|null $clock current time in seconds; tests
     *        inject a fake so heartbeats and back-off are deterministic.
     * @param (\Closure(float): void)|null $sleeper replaces usleep() in the
     *        reconnect back-off; tests inject a recorder.
     */
    public function __construct(
        private readonly ?WebSocketConnectionFactory $factory,
        private readonly string $url,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly float $heartbeatInterval = 30.0,
        private readonly bool $autoReconnect = false,
        private readonly float $reconnectBaseDelay = 1.0,
        private readonly float $reconnectMaxDelay = 30.0,
        private readonly ?Transport $transport = null,
        #[\SensitiveParameter] private ?string $accessToken = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $sleeper = null,
    ) {
        $this->serializer = new Serializer();
    }

    /**
     * @param array<string, mixed> $params `private` (bool), `presence_key`,
     *        `access_token` (defaults to the client's token)
     */
    public function channel(string $name, array $params = []): Channel
    {
        $topic = str_starts_with($name, 'realtime:') ? $name : 'realtime:' . $name;
        if (isset($this->channels[$topic])) {
            return $this->channels[$topic];
        }

        if (! array_key_exists('access_token', $params) && $this->accessToken !== null) {
            $params['access_token'] = $this->accessToken;
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

    /**
     * Sends a broadcast message over HTTP (POST /realtime/v1/api/broadcast)
     * without opening a WebSocket — suited to web requests. Subscribers of
     * the topic receive it as a regular broadcast event. The request carries
     * the client's bearer, so a user-bound client is authorised as that user.
     *
     * @param array<mixed> $payload
     */
    public function broadcast(string $topic, string $event, array $payload, bool $private = false): void
    {
        if ($this->transport === null) {
            throw new RealtimeException(
                'Realtime broadcast over HTTP needs the Transport; obtain the RealtimeClient through Client::realtime().'
            );
        }

        $topic = str_starts_with($topic, 'realtime:') ? substr($topic, strlen('realtime:')) : $topic;
        if (trim($topic) === '' || trim($event) === '') {
            throw new \InvalidArgumentException('Broadcast topic and event must not be empty.');
        }

        $response = $this->transport->request('POST', '/realtime/v1/api/broadcast', [
            'body' => ['messages' => [[
                'topic' => $topic,
                'event' => $event,
                'payload' => $payload === [] ? new \stdClass() : $payload,
                'private' => $private,
            ]]],
        ]);

        if ($response->getStatusCode() >= 400) {
            throw RealtimeException::fromResponse($response);
        }
    }

    /**
     * Replaces the user token: later joins carry it, and channels that are
     * already joined receive an access_token event so the server re-evaluates
     * their authorisation without a reconnect.
     */
    public function setAuth(#[\SensitiveParameter] ?string $accessToken): void
    {
        $this->accessToken = $accessToken;
        $connected = $this->conn?->isConnected() ?? false;

        foreach ($this->channels as $channel) {
            $channel->setAccessToken($accessToken);
            if ($accessToken !== null && $connected && $channel->state() === 'joined') {
                $channel->pushAccessToken();
            }
        }
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

        $conn = $this->requireFactory()->create();
        try {
            $conn->connect($this->buildUrl(), ['apikey' => $this->apiKey]);
        } catch (\Throwable $e) {
            throw $this->wrap($e, 'Failed to open Realtime connection');
        }

        $this->conn = $conn;
        $this->connectedOnce = true;
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
        $connected = $this->conn?->isConnected() ?? false;
        if (! $this->connectedOnce || (! $this->autoReconnect && ! $connected)) {
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

        $conn = $this->requireFactory()->create();
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
        if ($seconds <= 0.0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }
        usleep((int) ($seconds * 1_000_000));
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
        $this->connectedOnce = false;
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
            'accessToken' => $this->accessToken === null ? null : HeaderRedaction::REDACTED,
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
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private function requireFactory(): WebSocketConnectionFactory
    {
        return $this->factory ?? throw new RealtimeException(
            'Realtime requires a WebSocketConnectionFactory. Provide one via ClientOptions(webSocketFactory: ...). See the README.'
        );
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
