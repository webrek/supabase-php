<?php

declare(strict_types=1);

namespace Supabase;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Supabase\Functions\FunctionsClient;
use Supabase\Http\Transport;

final class Client
{
    private readonly Transport $transport;

    public function __construct(string $url, #[\SensitiveParameter] string $apiKey, ?ClientOptions $options = null)
    {
        $parsed = parse_url($url);
        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : '';
        $host = $parsed['host'] ?? '';

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException(
                "The Supabase URL must not contain userinfo (user:password): \"{$url}\"."
            );
        }

        if ($scheme === 'https' && $host !== '') {
            // always allowed
        } elseif ($scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1')) {
            // local dev exception
        } else {
            throw new \InvalidArgumentException(
                "The Supabase URL must use HTTPS with a host (got: \"{$url}\"). "
                . 'HTTP is only permitted for localhost / 127.0.0.1.'
            );
        }

        $options ??= new ClientOptions();

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

    /**
     * Prevents accidental persistence of credentials to cache/session.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Client must not be serialized; it holds credentials.');
    }

    private ?FunctionsClient $functions = null;

    public function functions(): FunctionsClient
    {
        return $this->functions ??= new FunctionsClient($this->transport);
    }
}
