<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

test('README documents Realtime and the WebSocketConnection adapter', function () {
    $readme = file_get_contents(__DIR__ . '/../../README.md');
    expect($readme)->toBeString();
    /** @var string $readme */
    expect($readme)->toContain('## Realtime')
        ->and($readme)->toContain('WebSocketConnection')
        ->and($readme)->toContain('webSocketFactory')
        ->and($readme)->toContain('onPostgresChanges')
        ->and($readme)->not->toContain('**Planned:**')
        ->and($readme)->toContain('Realtime (postgres changes')
        ->and($readme)->toContain('onPresenceSync')
        ->and($readme)->toContain('realtimeAutoReconnect');
});
