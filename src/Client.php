<?php

declare(strict_types=1);

namespace Supabase;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Supabase\Auth\GoTrueClient;
use Supabase\Functions\FunctionsClient;
use Supabase\Http\Transport;
use Supabase\Postgrest\FilterBuilder;
use Supabase\Postgrest\PostgrestClient;
use Supabase\Postgrest\QueryBuilder;

final class Client
{
    private readonly Transport $transport;

    private readonly string $schema;

    private readonly string $url;

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

        $this->url = $url;
        $this->schema = $options->schema;

        $httpClient = $options->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $options->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $options->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

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

    private ?GoTrueClient $auth = null;

    public function auth(): GoTrueClient
    {
        return $this->auth ??= new GoTrueClient($this->transport, $this->url);
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

    private ?FunctionsClient $functions = null;

    public function functions(): FunctionsClient
    {
        return $this->functions ??= new FunctionsClient($this->transport);
    }

    private ?PostgrestClient $postgrest = null;

    public function from(string $table): QueryBuilder
    {
        return ($this->postgrest ??= new PostgrestClient($this->transport, $this->schema))->from($table);
    }

    /**
     * @param array<mixed> $params
     */
    public function rpc(string $function, array $params = []): FilterBuilder
    {
        return ($this->postgrest ??= new PostgrestClient($this->transport, $this->schema))->rpc($function, $params);
    }
}
