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

    // The callback runs deep inside poll(); collect the first received change on
    // a small holder and read it back after each poll().
    $inbox = new class () {
        /** @var array<mixed>|null */
        private ?array $change = null;

        /** @param array<mixed> $change */
        public function capture(array $change): void
        {
            $this->change ??= $change;
        }

        /** @return array<mixed>|null */
        public function change(): ?array
        {
            return $this->change;
        }
    };

    $channel = $rt->channel('integration-db-changes')
        ->onPostgresChanges('INSERT', 'public', 'integration_items', null, function (array $change) use ($inbox): void {
            $inbox->capture($change);
        });

    $rt->connect();
    $channel->subscribe();

    // Wait for the subscription to be confirmed (phx_reply ok -> joined).
    $joinDeadline = microtime(true) + 10.0;
    while ($channel->state() !== 'joined' && microtime(true) < $joinDeadline) {
        $rt->poll(0.5);
    }
    expect($channel->state())->toBe('joined');

    // Realtime does not replay past changes and the replication subscription
    // becomes active a moment AFTER the join reply, so a single early INSERT can
    // be missed. Insert once per second (each with a unique name) while pumping
    // the loop, until a change is delivered or we time out.
    $prefix = 'rt-' . uniqid() . '-';
    $deadline = microtime(true) + 25.0;
    $change = null;
    $n = 0;
    $lastInsert = 0.0;
    while (microtime(true) < $deadline) {
        $now = microtime(true);
        if ($now - $lastInsert >= 1.0) {
            $db->from('integration_items')->insert(['name' => $prefix . $n])->execute();
            $n++;
            $lastInsert = $now;
        }

        $rt->poll(0.3);

        $change = $inbox->change();
        if ($change !== null) {
            break;
        }
    }

    $rt->disconnect();

    expect($change)->not->toBeNull();
    \assert(is_array($change));

    $new = $change['new'] ?? null;
    \assert(is_array($new), 'postgres_changes payload should carry the new row');
    $name = $new['name'] ?? null;
    expect(is_string($name) && str_starts_with($name, $prefix))->toBeTrue();
});
