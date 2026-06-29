<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

/**
 * End-to-end Realtime Presence test against a real Supabase stack: join a public
 * channel with presence enabled, track our own presence, and assert it shows up
 * in presenceState() (the server includes self in presence_state). Exercises the
 * presence wire format (presence config in phx_join, track push, presence_state
 * sync) against the real server.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Realtime: track() makes our presence appear in presenceState over a real connection', function () {
    $rt = IntegrationSupport::realtimeClient()->realtime();

    $key = 'user-' . uniqid();
    $channel = $rt->channel('presence-room', ['presence_key' => $key])
        ->onPresenceSync(fn () => null);

    $rt->connect();
    $channel->subscribe();

    // Wait for the subscription to be confirmed (phx_reply ok -> joined).
    $joinDeadline = microtime(true) + 10.0;
    while ($channel->state() !== 'joined' && microtime(true) < $joinDeadline) {
        $rt->poll(0.5);
    }
    expect($channel->state())->toBe('joined');

    // Announce our presence, then pump the loop until the server echoes it back
    // in the presence state (or time out).
    $channel->track(['online_at' => '2026-01-01T00:00:00Z']);

    $found = false;
    $deadline = microtime(true) + 15.0;
    while (microtime(true) < $deadline) {
        $rt->poll(0.3);
        if (array_key_exists($key, $channel->presenceState())) {
            $found = true;
            break;
        }
    }

    $rt->disconnect();

    expect($found)->toBeTrue();

    $presences = $channel->presenceState()[$key] ?? [];
    expect($presences)->not->toBeEmpty()
        ->and(($presences[0] ?? [])['online_at'] ?? null)->toBe('2026-01-01T00:00:00Z');
});
