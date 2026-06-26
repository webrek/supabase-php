<?php

declare(strict_types=1);

namespace Supabase\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Supabase\Exception\SupabaseException;

final class Transport
{
    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $defaultHeaders,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array{headers?: array<string,string>, query?: array<string,scalar>, body?: array<mixed>|string} $options
     */
    public function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if (isset($options['query']) && $options['query'] !== []) {
            $url .= '?' . http_build_query($options['query']);
        }

        $request = $this->requestFactory->createRequest($method, $url);

        $headers = $this->defaultHeaders;
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[$name] = $value;
        }

        if (array_key_exists('body', $options)) {
            $body = $options['body'];
            if (is_array($body)) {
                $headers['Content-Type'] ??= 'application/json';
                $body = json_encode($body, JSON_THROW_ON_ERROR);
            }
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new SupabaseException($e->getMessage(), previous: $e);
        }
    }
}
