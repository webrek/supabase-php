<?php

declare(strict_types=1);

namespace Supabase\Realtime;

/**
 * Persistent WebSocket connection used by the Realtime client.
 *
 * The SDK ships no concrete implementation: the consumer provides one (a
 * reference adapter is documented in the README). Implementations MUST throw a
 * Supabase\Exception\SupabaseException (or subclass) on network failure;
 * RealtimeClient wraps anything else in a RealtimeException.
 */
interface WebSocketConnection
{
    /**
     * Open the connection. The URL already includes the apikey query parameter,
     * so it MUST NOT be logged verbatim.
     *
     * @param array<string, string> $headers
     */
    public function connect(string $url, array $headers = []): void;

    public function send(string $data): void;

    /**
     * Return the next text frame, or null if the timeout elapsed with no
     * message. MUST NOT block longer than $timeoutSeconds.
     */
    public function receive(float $timeoutSeconds): ?string;

    public function close(int $code = 1000, string $reason = ''): void;

    public function isConnected(): bool;
}
