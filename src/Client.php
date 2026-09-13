<?php

declare(strict_types=1);

namespace Supabase;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Supabase\Auth\GoTrueClient;
use Supabase\Auth\Session;
use Supabase\Exception\RealtimeException;
use Supabase\Functions\FunctionsClient;
use Supabase\Http\HeaderRedaction;
use Supabase\Http\Transport;
use Supabase\Postgrest\FilterBuilder;
use Supabase\Postgrest\PostgrestClient;
use Supabase\Postgrest\QueryBuilder;
use Supabase\Realtime\RealtimeClient;
use Supabase\Storage\StorageClient;

final class Client
{
    private readonly Transport $transport;

    private readonly string $url;

    private readonly string $apiKey;

    /** Options with the PSR-18 client and PSR-17 factories already resolved. */
    private readonly ClientOptions $options;

    public function __construct(string $url, #[\SensitiveParameter] string $apiKey, ?ClientOptions $options = null)
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new \InvalidArgumentException('The Supabase URL is malformed.');
        }

        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : '';
        $host = $parsed['host'] ?? '';

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException(
                'The Supabase URL must not contain userinfo (user:password).'
            );
        }

        if ($host === '') {
            throw new \InvalidArgumentException('The Supabase URL must include a host.');
        }

        if ($scheme === 'https') {
            // always allowed
        } elseif ($scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1')) {
            // local dev exception
        } else {
            throw new \InvalidArgumentException(
                'The Supabase URL must use HTTPS. HTTP is only permitted for localhost / 127.0.0.1.'
            );
        }

        $options ??= new ClientOptions();

        $httpClient = $options->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $options->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $options->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        $this->url = $url;
        $this->apiKey = $apiKey;
        // Resolve discovery once so siblings built by withAccessToken() share
        // the same HTTP client and factories.
        $this->options = $options->withHttp($httpClient, $requestFactory, $streamFactory);

        $headers = [
            'apikey' => $apiKey,
            'Authorization' => 'Bearer ' . ($options->accessToken ?? $apiKey),
        ];
        foreach ($options->headers as $name => $value) {
            $headers[$name] = $value;
        }

        $this->transport = new Transport(
            $url,
            $headers,
            $httpClient,
            $requestFactory,
            $streamFactory,
        );
    }

    public function getTransport(): Transport
    {
        return $this->transport;
    }

    /**
     * Returns a sibling client whose requests carry the given user JWT as
     * `Authorization: Bearer`, so Row Level Security applies as that user.
     * The apikey, HTTP client and every option are kept; null reverts to the
     * apikey as bearer. This client is left unchanged. As in the constructor,
     * a custom `Authorization` in ClientOptions::$headers still wins.
     */
    public function withAccessToken(#[\SensitiveParameter] ?string $accessToken): self
    {
        if ($accessToken !== null && trim($accessToken) === '') {
            throw new \InvalidArgumentException('The access token must not be empty.');
        }

        return new self($this->url, $this->apiKey, $this->options->withAccessToken($accessToken));
    }

    /**
     * Returns a sibling client authenticated as the session's user. When the
     * session is expired, or expires within $expiryMargin seconds, it is
     * refreshed first and the new Session is handed to $onTokenRefreshed so
     * the caller can persist it. Refresh is proactive only: a token that
     * expires mid-request is not retried. Throws AuthException when the
     * refresh token is no longer valid.
     *
     * @param null|callable(Session): void $onTokenRefreshed
     */
    public function withSession(Session $session, ?callable $onTokenRefreshed = null, int $expiryMargin = 30): self
    {
        if ($session->isExpired($expiryMargin)) {
            $session = $this->auth()->refreshSession($session->refreshToken);
            if ($onTokenRefreshed !== null) {
                $onTokenRefreshed($session);
            }
        }

        return $this->withAccessToken($session->accessToken);
    }

    private ?GoTrueClient $auth = null;

    public function auth(): GoTrueClient
    {
        return $this->auth ??= new GoTrueClient(
            $this->transport,
            $this->url,
            $this->options->jwksCache,
            $this->options->jwksCacheTtl,
            $this->options->jwtSecret,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'url' => $this->url,
            'schema' => $this->options->schema,
            'apiKey' => HeaderRedaction::REDACTED,
            'transport' => $this->transport,
            'webSocketFactory' => $this->options->webSocketFactory,
        ];
    }

    /**
     * Prevents accidental persistence of credentials to cache/session.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Client must not be serialized; it holds credentials.');
    }

    /**
     * Prevents reconstruction of a credential-holding object from untrusted data.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Client must not be unserialized; it holds credentials.');
    }

    private ?StorageClient $storage = null;

    public function storage(): StorageClient
    {
        return $this->storage ??= new StorageClient($this->transport, $this->url);
    }

    private ?RealtimeClient $realtime = null;

    public function realtime(): RealtimeClient
    {
        $factory = $this->options->webSocketFactory;
        if ($factory === null) {
            throw new RealtimeException(
                'Realtime requires a WebSocketConnectionFactory. Provide one via ClientOptions(webSocketFactory: ...). See the README.'
            );
        }

        return $this->realtime ??= new RealtimeClient(
            $factory,
            $this->url,
            $this->apiKey,
            heartbeatInterval: $this->options->realtimeHeartbeatInterval,
            autoReconnect: $this->options->realtimeAutoReconnect,
            reconnectBaseDelay: $this->options->realtimeReconnectBaseDelay,
            reconnectMaxDelay: $this->options->realtimeReconnectMaxDelay,
        );
    }

    private ?FunctionsClient $functions = null;

    public function functions(): FunctionsClient
    {
        return $this->functions ??= new FunctionsClient($this->transport);
    }

    private ?PostgrestClient $postgrest = null;

    public function from(string $table): QueryBuilder
    {
        return ($this->postgrest ??= new PostgrestClient($this->transport, $this->options->schema))->from($table);
    }

    /**
     * @param array<mixed> $params
     */
    public function rpc(string $function, array $params = []): FilterBuilder
    {
        return ($this->postgrest ??= new PostgrestClient($this->transport, $this->options->schema))->rpc($function, $params);
    }
}
