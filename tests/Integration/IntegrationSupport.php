<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Supabase\Auth\Session;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Realtime\Channel;
use Supabase\Realtime\PhrityWebSocketConnectionFactory;
use Supabase\Realtime\RealtimeClient;

/**
 * Shared helpers for integration tests.
 *
 * All methods assume that the SUPABASE_* env vars are set; the Pest beforeEach
 * in tests/Pest.php marks every Integration test as skipped when they are not.
 */
final class IntegrationSupport
{
    /**
     * Returns a Client authenticated with the service-role key.
     *
     * Suitable for PostgREST (Database) and Storage — the service-role key
     * bypasses RLS so test rows are always readable/writable without policy
     * configuration on the test table.
     */
    public static function client(): Client
    {
        $url = getenv('SUPABASE_URL');
        $key = getenv('SUPABASE_SERVICE_ROLE_KEY');

        assert(is_string($url) && $url !== '', 'SUPABASE_URL env var must be set');
        assert(is_string($key) && $key !== '', 'SUPABASE_SERVICE_ROLE_KEY env var must be set');

        $factory = new Psr17Factory();

        return new Client($url, $key, new ClientOptions(
            httpClient: new GuzzleClient(['allow_redirects' => false, 'timeout' => 10.0]),
            requestFactory: $factory,
            streamFactory: $factory,
        ));
    }

    /**
     * Returns a Client authenticated with the anon key.
     *
     * Suitable for Auth signup/signin flows that must go through GoTrue with
     * public-facing credentials, as a real user would.
     */
    public static function authClient(?string $jwtSecret = null): Client
    {
        $url = getenv('SUPABASE_URL');
        $key = getenv('SUPABASE_ANON_KEY');

        assert(is_string($url) && $url !== '', 'SUPABASE_URL env var must be set');
        assert(is_string($key) && $key !== '', 'SUPABASE_ANON_KEY env var must be set');

        $factory = new Psr17Factory();

        return new Client($url, $key, new ClientOptions(
            httpClient: new GuzzleClient(['allow_redirects' => false, 'timeout' => 10.0]),
            requestFactory: $factory,
            streamFactory: $factory,
            jwtSecret: $jwtSecret,
        ));
    }

    /** The stack's legacy HS256 secret (SUPABASE_JWT_SECRET), when exported. */
    public static function jwtSecret(): ?string
    {
        $secret = getenv('SUPABASE_JWT_SECRET');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /** Base URL of the local mail catcher (SUPABASE_MAIL_URL, Mailpit), when exported. */
    public static function mailUrl(): ?string
    {
        $url = getenv('SUPABASE_MAIL_URL');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * Signs up a fresh user through GoTrue and returns its Session. The CLI
     * stack has email confirmation disabled, so signUp returns a Session.
     */
    public static function signUp(Client $client): Session
    {
        $session = $client->auth()->signUp(uniqid('itest_') . '@example.com', 'Testing1234!');
        if ($session === null) {
            throw new \RuntimeException('signUp returned no Session: this stack requires email confirmation.');
        }

        return $session;
    }

    /**
     * Pumps the Realtime loop until the channel leaves the "joining" state or
     * the timeout elapses, and returns the state it ended in.
     */
    public static function waitForJoin(RealtimeClient $rt, Channel $channel, float $timeout = 10.0): string
    {
        $deadline = microtime(true) + $timeout;
        while ($channel->state() === 'joining' && microtime(true) < $deadline) {
            $rt->poll(0.5);
        }

        return $channel->state();
    }

    /**
     * Finds the first GoTrue /auth/v1/verify link in the latest Mailpit message
     * sent to $email, polling until $timeout. Returns null when none arrives.
     */
    public static function waitForVerifyLink(string $mailUrl, string $email, float $timeout = 15.0): ?string
    {
        $http = new GuzzleClient(['timeout' => 5.0, 'http_errors' => false]);
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $search = $http->get($mailUrl . '/api/v1/search?query=' . rawurlencode('to:' . $email));
            $list = json_decode((string) $search->getBody(), true);
            $messages = is_array($list) && isset($list['messages']) && is_array($list['messages']) ? $list['messages'] : [];
            $first = $messages[0] ?? null;
            $id = is_array($first) && isset($first['ID']) && is_string($first['ID']) ? $first['ID'] : null;

            if ($id !== null) {
                $message = json_decode((string) $http->get($mailUrl . '/api/v1/message/' . rawurlencode($id))->getBody(), true);
                $bodies = is_array($message) ? [$message['Text'] ?? '', $message['HTML'] ?? ''] : [];
                foreach ($bodies as $body) {
                    if (is_string($body) && preg_match('#https?://[^\s"\'<>]+/auth/v1/verify[^\s"\'<>]*#', $body, $m) === 1) {
                        return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5);
                    }
                }
            }

            usleep(500_000);
        }

        return null;
    }

    /**
     * Returns a Client with the anon key and a real WebSocket transport (the
     * phrity/websocket reference adapter) for Realtime integration tests.
     *
     * Realtime authorizes postgres_changes delivery via RLS, so the subscriber
     * uses the anon role (the test table has a permissive anon SELECT policy).
     */
    public static function realtimeClient(): Client
    {
        $url = getenv('SUPABASE_URL');
        $key = getenv('SUPABASE_ANON_KEY');

        assert(is_string($url) && $url !== '', 'SUPABASE_URL env var must be set');
        assert(is_string($key) && $key !== '', 'SUPABASE_ANON_KEY env var must be set');

        $factory = new Psr17Factory();

        return new Client($url, $key, new ClientOptions(
            httpClient: new GuzzleClient(['allow_redirects' => false, 'timeout' => 10.0]),
            requestFactory: $factory,
            streamFactory: $factory,
            webSocketFactory: new PhrityWebSocketConnectionFactory(),
        ));
    }

    /**
     * Extracts the first row from a PostgREST result as a string-keyed array.
     *
     * Throws RuntimeException if the result is null or empty, providing a clear
     * failure message rather than a cryptic offset error.
     *
     * @param array<mixed>|null $result
     * @return array<string, mixed>
     */
    public static function firstRow(array|null $result): array
    {
        if (! is_array($result) || ! isset($result[0]) || ! is_array($result[0])) {
            throw new \RuntimeException(
                'Expected at least one row in PostgREST result, got: ' . var_export($result, true)
            );
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return $row;
    }
}
