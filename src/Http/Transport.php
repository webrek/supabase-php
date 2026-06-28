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
     * Returns debug information with sensitive header values redacted so that
     * var_dump() / print_r() / crash reporters cannot expose live credentials.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'defaultHeaders' => HeaderRedaction::redact($this->defaultHeaders),
        ];
    }

    /**
     * Prevents accidental persistence of credentials to cache/session.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Transport must not be serialized; it holds credentials.');
    }

    /**
     * Prevents reconstruction of a credential-holding object from untrusted data.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Transport must not be unserialized; it holds credentials.');
    }

    /**
     * @param array{headers?: array<string,string>, query?: array<string,scalar>|list<array{0:string,1:string}>, body?: array<mixed>|string} $options
     */
    public function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        $url = rtrim($this->baseUrl, '/') . $path;
        $query = $options['query'] ?? null;
        if (is_array($query) && $query !== []) {
            if (array_is_list($query)) {
                $first = reset($query);
                if (is_array($first)) {
                    /** @var list<array{0:string,1:string}> $query */
                    $url .= '?' . $this->buildOrderedQueryString($query);
                } else {
                    $url .= '?' . http_build_query($query);
                }
            } else {
                /** @var array<string, scalar> $query */
                $url .= '?' . http_build_query($query);
            }
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
                try {
                    $body = json_encode($body, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new SupabaseException('Failed to encode request body as JSON: ' . $e->getMessage(), previous: $e);
                }
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

    /**
     * Builds a query string from an ordered list of key-value pairs.
     * Preserves order and allows duplicate keys.
     *
     * @param list<array{0:string,1:string}> $pairs
     */
    private function buildOrderedQueryString(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as [$key, $value]) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        return implode('&', $parts);
    }
}
