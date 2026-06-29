<?php

declare(strict_types=1);

namespace Supabase\Realtime;

use Supabase\Exception\RealtimeException;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Close;
use WebSocket\Message\Text;
use WebSocket\Middleware\PingResponder;

/**
 * WebSocketConnection adapter backed by phrity/websocket.
 *
 * Install the backing library with:
 *     composer require phrity/websocket
 *
 * Usage with RealtimeClient:
 *     new RealtimeClient(new PhrityWebSocketConnectionFactory(), ...);
 *
 * Read-timeout detection: phrity throws ConnectionTimeoutException when the
 * underlying PHP stream's timed_out metadata flag is set after setTimeout().
 * This adapter catches that exception and returns null.
 */
final class PhrityWebSocketConnection implements WebSocketConnection
{
    /**
     * When provided, connect() invokes this closure with ($url, $headers)
     * instead of building the real phrity Client. Useful for testing.
     *
     * @var (\Closure(string, array<string, string>): Client)|null
     */
    private readonly ?\Closure $clientFactory;

    private ?Client $client = null;

    /**
     * @param (\Closure(string, array<string, string>): Client)|null $clientFactory
     *     Optional factory for the phrity Client. When null (the default), a
     *     real Client is constructed and connected on the first connect() call.
     *     Pass a closure to inject a test double or a pre-configured client.
     */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, string> $headers
     */
    public function connect(string $url, array $headers = []): void
    {
        if ($this->clientFactory !== null) {
            $this->client = ($this->clientFactory)($url, $headers);
            return;
        }

        if (!class_exists(Client::class)) {
            throw new RealtimeException(
                'phrity/websocket is not installed. Run: composer require phrity/websocket'
            );
        }

        $client = new Client($url);
        $client->addMiddleware(new PingResponder());

        foreach ($headers as $name => $value) {
            $client->addHeader($name, $value);
        }

        $client->connect();
        $this->client = $client;
    }

    public function send(string $data): void
    {
        $this->resolvedClient()->text($data);
    }

    /**
     * Returns the next text frame, or null when $timeoutSeconds elapses with
     * no message. Non-text control frames (ping/pong — handled by PingResponder
     * middleware) are skipped; only text payloads are surfaced. A Close frame
     * from the remote throws a RealtimeException.
     */
    public function receive(float $timeoutSeconds): ?string
    {
        $client = $this->resolvedClient();
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0.0) {
                return null;
            }

            $client->setTimeout($remaining);

            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                return null;
            } catch (\Throwable $e) {
                throw new RealtimeException(
                    'WebSocket receive error: ' . $e->getMessage(),
                    previous: $e
                );
            }

            if ($message instanceof Text) {
                return $message->getContent();
            }

            if ($message instanceof Close) {
                $detail = $message->getContent();
                throw new RealtimeException(
                    'WebSocket connection closed by remote' .
                    ($detail !== '' ? ': ' . $detail : '')
                );
            }

            // Ping/Pong/Binary: PingResponder auto-responds to pings; non-text
            // frames are ignored here — loop back and await the next frame.
        }
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->client === null) {
            return;
        }

        try {
            $this->client->close($code, $reason);
        } catch (\Throwable) {
            // best-effort: send the close frame if possible
        }

        $this->client->disconnect();
        $this->client = null;
    }

    public function isConnected(): bool
    {
        return $this->client !== null && $this->client->isConnected();
    }

    private function resolvedClient(): Client
    {
        if ($this->client === null || !$this->client->isConnected()) {
            throw new RealtimeException('Not connected. Call connect() first.');
        }

        return $this->client;
    }
}
