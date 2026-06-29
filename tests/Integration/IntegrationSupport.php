<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Realtime\PhrityWebSocketConnectionFactory;

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
    public static function authClient(): Client
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
        ));
    }

    /**
     * Returns a Client with the service-role key and a real WebSocket transport
     * (the phrity/websocket reference adapter) for Realtime integration tests.
     */
    public static function realtimeClient(): Client
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
