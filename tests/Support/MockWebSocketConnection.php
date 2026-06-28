<?php

declare(strict_types=1);

namespace Supabase\Tests\Support;

use Supabase\Realtime\WebSocketConnection;

final class MockWebSocketConnection implements WebSocketConnection
{
    /** @var list<string> */
    public array $sent = [];

    /** @var list<string> */
    private array $inbound = [];

    public bool $connected = false;

    public ?string $connectedUrl = null;

    /** @var array<string, string> */
    public array $connectedHeaders = [];

    public function queue(string $frame): void
    {
        $this->inbound[] = $frame;
    }

    public function connect(string $url, array $headers = []): void
    {
        $this->connected = true;
        $this->connectedUrl = $url;
        $this->connectedHeaders = $headers;
    }

    public function send(string $data): void
    {
        $this->sent[] = $data;
    }

    public function receive(float $timeoutSeconds): ?string
    {
        return array_shift($this->inbound);
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
