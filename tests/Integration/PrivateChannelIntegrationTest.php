<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

/**
 * Private channels against a real Realtime server with the RLS policies from
 * the integration migration: an anon join is refused, an authenticated user
 * (token sent with the join by withSession()) is admitted, a private REST
 * broadcast sent as that user reaches them, and setAuth() rotates the token
 * without dropping the subscription.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Realtime: private channels refuse anon, admit a signed-in user and accept a rotated token', function (): void {
    $anon = IntegrationSupport::realtimeClient();
    $session = IntegrationSupport::signUp($anon);
    $topic = 'private-' . uniqid();

    // anon: the join is rejected by Realtime Authorization (no policy for anon).
    $anonRt = $anon->realtime();
    $anonRt->connect();
    $anonChannel = $anonRt->channel($topic, ['private' => true])->subscribe();
    $anonState = IntegrationSupport::waitForJoin($anonRt, $anonChannel);
    $anonRt->disconnect();
    expect($anonState)->not->toBe('joined')
        ->and($anonState)->not->toBe('joining');

    // Note: the REST endpoint accepts (202) a private broadcast from anon and
    // applies the RLS policy at delivery time, so there is nothing to assert on
    // the anon POST itself; the join refusal above is the enforced boundary.

    // user: joined, and receives a private broadcast posted over HTTP as that user.
    $asUser = $anon->withSession($session);
    $userRt = $asUser->realtime();

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

    $userRt->connect();
    $userChannel = $userRt->channel($topic, ['private' => true])
        ->onBroadcast('secret', function (array $message) use ($inbox): void {
            $inbox->capture($message);
        })
        ->subscribe();
    expect($userChannel->isPrivate())->toBeTrue()
        ->and(IntegrationSupport::waitForJoin($userRt, $userChannel))->toBe('joined');

    $marker = uniqid();
    $deadline = microtime(true) + 20.0;
    $lastSend = 0.0;
    while (microtime(true) < $deadline && $inbox->message() === null) {
        if (microtime(true) - $lastSend >= 1.0) {
            $asUser->realtime()->broadcast($topic, 'secret', ['marker' => $marker], private: true);
            $lastSend = microtime(true);
        }
        $userRt->poll(0.3);
    }

    $message = $inbox->message();
    expect($message)->not->toBeNull();
    \assert(is_array($message));
    $payload = $message['payload'] ?? null;
    \assert(is_array($payload));
    expect($payload['marker'] ?? null)->toBe($marker);

    // Rotate the token in place: the channel must stay joined after the access_token push.
    $fresh = $anon->auth()->refreshSession($session->refreshToken);
    $userRt->setAuth($fresh->accessToken);
    $settle = microtime(true) + 2.0;
    while (microtime(true) < $settle) {
        $userRt->poll(0.3);
    }
    $userRt->disconnect();

    expect($userChannel->state())->toBe('joined');
});
