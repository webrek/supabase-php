<?php

declare(strict_types=1);

namespace Supabase\Storage;

use Supabase\Exception\StorageException;
use Supabase\Http\ResponseBody;
use Supabase\Http\Transport;

final class StorageHttp
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param array{body?: array<mixed>|string|\Psr\Http\Message\StreamInterface, headers?: array<string,string>, query?: array<string,scalar>|list<array{0:string,1:string}>} $options
     * @return array<mixed>
     */
    public function requestJson(string $method, string $path, array $options = []): array
    {
        $response = $this->transport->request($method, '/storage/v1' . $path, $options);

        if ($response->getStatusCode() >= 400) {
            throw StorageException::fromResponse($response);
        }

        $body = ResponseBody::read($response->getBody());
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException('Invalid JSON in Storage response: ' . $e->getMessage(), previous: $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{body?: array<mixed>|string|\Psr\Http\Message\StreamInterface, headers?: array<string,string>, query?: array<string,scalar>|list<array{0:string,1:string}>} $options
     */
    public function requestRaw(string $method, string $path, array $options = [], int $maxBytes = 52_428_800): string
    {
        $response = $this->transport->request($method, '/storage/v1' . $path, $options);

        if ($response->getStatusCode() >= 400) {
            throw StorageException::fromResponse($response);
        }

        return ResponseBody::read($response->getBody(), $maxBytes);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['transport' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('StorageHttp must not be serialized; it holds a credentialed transport.');
    }

    /**
     * @param array<string,mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('StorageHttp must not be unserialized; it holds a credentialed transport.');
    }
}
