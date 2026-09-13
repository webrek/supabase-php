<?php

declare(strict_types=1);

namespace Supabase;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Supabase\Http\HeaderRedaction;
use Supabase\Realtime\WebSocketConnectionFactory;

final readonly class ClientOptions implements \JsonSerializable
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
        public array $headers = [],
        public string $schema = 'public',
        #[\SensitiveParameter] public ?string $accessToken = null,
        public ?WebSocketConnectionFactory $webSocketFactory = null,
        public float $realtimeHeartbeatInterval = 30.0,
        public bool $realtimeAutoReconnect = false,
        public float $realtimeReconnectBaseDelay = 1.0,
        public float $realtimeReconnectMaxDelay = 30.0,
        public ?CacheInterface $jwksCache = null,
        public int $jwksCacheTtl = 600,
        #[\SensitiveParameter] public ?string $jwtSecret = null,
    ) {
    }

    /**
     * Returns a copy with the PSR-18 client and PSR-17 factories filled in.
     * The Client resolves discovery once and keeps the result here so that
     * siblings built by Client::withAccessToken() share the same instances.
     */
    public function withHttp(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ): self {
        return new self(
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            headers: $this->headers,
            schema: $this->schema,
            accessToken: $this->accessToken,
            webSocketFactory: $this->webSocketFactory,
            realtimeHeartbeatInterval: $this->realtimeHeartbeatInterval,
            realtimeAutoReconnect: $this->realtimeAutoReconnect,
            realtimeReconnectBaseDelay: $this->realtimeReconnectBaseDelay,
            realtimeReconnectMaxDelay: $this->realtimeReconnectMaxDelay,
            jwksCache: $this->jwksCache,
            jwksCacheTtl: $this->jwksCacheTtl,
            jwtSecret: $this->jwtSecret,
        );
    }

    /**
     * Returns a copy with a different access token; null falls back to the
     * apikey as bearer. Everything else is kept.
     */
    public function withAccessToken(#[\SensitiveParameter] ?string $accessToken): self
    {
        return new self(
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            headers: $this->headers,
            schema: $this->schema,
            accessToken: $accessToken,
            webSocketFactory: $this->webSocketFactory,
            realtimeHeartbeatInterval: $this->realtimeHeartbeatInterval,
            realtimeAutoReconnect: $this->realtimeAutoReconnect,
            realtimeReconnectBaseDelay: $this->realtimeReconnectBaseDelay,
            realtimeReconnectMaxDelay: $this->realtimeReconnectMaxDelay,
            jwksCache: $this->jwksCache,
            jwksCacheTtl: $this->jwksCacheTtl,
            jwtSecret: $this->jwtSecret,
        );
    }

    /**
     * Returns debug information with the access token and any sensitive headers
     * redacted so that var_dump() / print_r() / crash reporters cannot expose
     * live credentials.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'httpClient' => $this->httpClient,
            'requestFactory' => $this->requestFactory,
            'streamFactory' => $this->streamFactory,
            'headers' => HeaderRedaction::redact($this->headers),
            'schema' => $this->schema,
            'accessToken' => $this->accessToken === null ? null : HeaderRedaction::REDACTED,
            'webSocketFactory' => $this->webSocketFactory,
            'realtimeHeartbeatInterval' => $this->realtimeHeartbeatInterval,
            'realtimeAutoReconnect' => $this->realtimeAutoReconnect,
            'realtimeReconnectBaseDelay' => $this->realtimeReconnectBaseDelay,
            'realtimeReconnectMaxDelay' => $this->realtimeReconnectMaxDelay,
            'jwksCache' => $this->jwksCache,
            'jwksCacheTtl' => $this->jwksCacheTtl,
            'jwtSecret' => $this->jwtSecret === null ? null : HeaderRedaction::REDACTED,
        ];
    }

    /**
     * Returns a redacted representation when json_encode() is called on this object.
     * Prevents credentials leaking into JSON-serialized logs or responses.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Prevents accidental persistence of credentials to cache/session.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('ClientOptions must not be serialized; it holds credentials.');
    }

    /**
     * Prevents reconstruction of a credential-holding object from untrusted data.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('ClientOptions must not be unserialized; it holds credentials.');
    }
}
