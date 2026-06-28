<?php

declare(strict_types=1);

namespace Supabase\Realtime;

/**
 * Creates fresh, unconnected WebSocketConnection instances. RealtimeClient owns
 * URL construction and connection lifecycle.
 */
interface WebSocketConnectionFactory
{
    public function create(): WebSocketConnection;
}
