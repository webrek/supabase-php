<?php

declare(strict_types=1);

namespace Supabase;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Supabase\Http\HeaderRedaction;

final readonly class ClientOptions
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
    ) {
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
        ];
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
}
