<?php

declare(strict_types=1);

namespace Supabase\Tests\Support;

use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;

final class MockWebSocketConnectionFactory implements WebSocketConnectionFactory
{
    public function __construct(private readonly WebSocketConnection $connection)
    {
    }

    public function create(): WebSocketConnection
    {
        return $this->connection;
    }
}
