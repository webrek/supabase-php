<?php

declare(strict_types=1);

namespace Supabase\Tests\Support;

use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Message;

/**
 * Test double for WebSocket\Client.
 *
 * Extends the real Client (not final) but overrides all network-touching methods
 * so no real socket is opened. Use queueReceive() to enqueue messages or
 * exceptions that receive() should return/throw.
 */
final class WebSocketClientSpy extends Client
{
    /** @var list<Message|\Throwable> */
    public array $receiveQueue = [];

    /** @var list<Message> */
    public array $sentMessages = [];

    public bool $connected = true;

    public function __construct()
    {
        // Parent constructor only parses the URI and initialises config/runner —
        // it does not open a network connection.
        parent::__construct('wss://spy.example.com');
    }

    /** Enqueue a message or throwable that receive() will return/throw in order. */
    public function queueReceive(Message|\Throwable $item): void
    {
        $this->receiveQueue[] = $item;
    }

    /**
     * No-op: the spy is considered "connected" from construction.
     */
    public function connect(): void
    {
        // intentional no-op
    }

    /**
     * Record the sent message without touching the network.
     */
    public function send(Message $message): Message
    {
        $this->sentMessages[] = $message;
        return $message;
    }

    /**
     * Return the next queued message, or throw the next queued throwable.
     * When the queue is empty, simulate a read timeout.
     */
    public function receive(): Message
    {
        if ($this->receiveQueue === []) {
            throw new ConnectionTimeoutException();
        }

        $next = array_shift($this->receiveQueue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }
}
