<?php

declare(strict_types=1);

namespace Supabase\Realtime;

/**
 * Factory that produces PhrityWebSocketConnection instances.
 *
 * Requires phrity/websocket to be installed:
 *     composer require phrity/websocket
 */
final class PhrityWebSocketConnectionFactory implements WebSocketConnectionFactory
{
    public function create(): WebSocketConnection
    {
        return new PhrityWebSocketConnection();
    }
}
