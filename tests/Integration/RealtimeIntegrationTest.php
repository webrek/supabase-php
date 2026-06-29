<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

/**
 * End-to-end Realtime test against a real Supabase stack: subscribe to
 * postgres_changes over a real WebSocket (the phrity adapter), trigger an INSERT
 * via PostgREST, and assert the change is delivered to the callback. This is the
 * test that exercises the Phoenix wire format against the real server — the kind
 * of contract mismatch that mock-only tests cannot catch.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Realtime: postgres_changes delivers an INSERT over a real WebSocket connection', function () {
    $rt = IntegrationSupport::realtimeClient()->realtime();
    $db = IntegrationSupport::client();

    $received = [];
    $channel = $rt->channel('integration-db-changes')
        ->onPostgresChanges('INSERT', 'public', 'integration_items', null, function (array $change) use (&$received): void {
            $received[] = $change;
        });

    $rt->connect();
    $channel->subscribe();

    // Wait for the subscription to be confirmed (phx_reply ok -> joined).
    $deadline = microtime(true) + 10.0;
    while ($channel->state() !== 'joined' && microtime(true) < $deadline) {
        $rt->poll(0.5);
    }
    expect($channel->state())->toBe('joined');

    // Trigger a change via PostgREST.
    $name = 'rt-' . uniqid();
    $db->from('integration_items')->insert(['name' => $name])->execute();

    // Pump the loop until the change arrives (or time out).
    $deadline = microtime(true) + 15.0;
    while ($received === [] && microtime(true) < $deadline) {
        $rt->poll(0.5);
    }

    $rt->disconnect();

    expect($received)->not->toBeEmpty();

    $new = $received[0]['new'] ?? null;
    \assert(is_array($new), 'postgres_changes payload should carry the new row');
    expect($new['name'] ?? null)->toBe($name);
});
