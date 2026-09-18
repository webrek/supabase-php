<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

/**
 * broadcast() over HTTP against a real Realtime server: a WebSocket subscriber
 * must receive a message posted through /realtime/v1/api/broadcast by a client
 * that never opened a socket.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Realtime: broadcast() over HTTP reaches a WebSocket subscriber', function (): void {
    $rt = IntegrationSupport::realtimeClient()->realtime();
    $sender = IntegrationSupport::client()->realtime(); // service role, no WebSocket factory

    $inbox = new class () {
        /** @var array<mixed>|null */
        private ?array $message = null;

        /** @param array<mixed> $message */
        public function capture(array $message): void
        {
            $this->message ??= $message;
        }

        /** @return array<mixed>|null */
        public function message(): ?array
        {
            return $this->message;
        }
    };

    $topic = 'rest-' . uniqid();
    $channel = $rt->channel($topic)->onBroadcast('ping', function (array $message) use ($inbox): void {
        $inbox->capture($message);
    });

    $rt->connect();
    $channel->subscribe();
    expect(IntegrationSupport::waitForJoin($rt, $channel))->toBe('joined');

    // Broadcast has no replay, so send once per second while pumping the loop.
    $marker = uniqid();
    $deadline = microtime(true) + 20.0;
    $lastSend = 0.0;
    while (microtime(true) < $deadline && $inbox->message() === null) {
        if (microtime(true) - $lastSend >= 1.0) {
            $sender->broadcast($topic, 'ping', ['marker' => $marker]);
            $lastSend = microtime(true);
        }
        $rt->poll(0.3);
    }
    $rt->disconnect();

    $message = $inbox->message();
    expect($message)->not->toBeNull();
    \assert(is_array($message));
    expect($message['event'] ?? null)->toBe('ping');
    $payload = $message['payload'] ?? null;
    \assert(is_array($payload));
    expect($payload['marker'] ?? null)->toBe($marker);
});
