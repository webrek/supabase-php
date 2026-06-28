<?php

declare(strict_types=1);

namespace Supabase\Storage;

use Supabase\Http\Transport;

final class StorageClient
{
    private readonly StorageHttp $http;

    private readonly string $baseUrl;

    public function __construct(Transport $transport, string $baseUrl)
    {
        $this->http = new StorageHttp($transport);
        $this->baseUrl = $baseUrl;
    }

    /**
     * @param array{public?: bool, file_size_limit?: int|string|null, allowed_mime_types?: list<string>} $options
     */
    public function createBucket(string $id, array $options = []): string
    {
        $body = ['id' => $id, 'name' => $id, 'public' => $options['public'] ?? false];
        if (array_key_exists('file_size_limit', $options)) {
            $body['file_size_limit'] = $options['file_size_limit'];
        }
        if (array_key_exists('allowed_mime_types', $options)) {
            $body['allowed_mime_types'] = $options['allowed_mime_types'];
        }

        $data = $this->http->requestJson('POST', '/bucket', ['body' => $body]);

        return isset($data['name']) && is_string($data['name']) ? $data['name'] : $id;
    }

    public function getBucket(string $id): Bucket
    {
        return Bucket::fromArray($this->normalizeStringKeys(
            $this->http->requestJson('GET', '/bucket/' . rawurlencode($id))
        ));
    }

    /**
     * @return list<Bucket>
     */
    public function listBuckets(): array
    {
        $data = $this->http->requestJson('GET', '/bucket');

        $buckets = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $buckets[] = Bucket::fromArray($this->normalizeStringKeys($row));
            }
        }

        return $buckets;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function updateBucket(string $id, array $options): void
    {
        $this->http->requestJson('PUT', '/bucket/' . rawurlencode($id), ['body' => $options]);
    }

    public function deleteBucket(string $id): void
    {
        $this->http->requestJson('DELETE', '/bucket/' . rawurlencode($id));
    }

    public function emptyBucket(string $id): void
    {
        $this->http->requestJson('POST', '/bucket/' . rawurlencode($id) . '/empty');
    }

    /**
     * Coerces an array with mixed keys to string keys, as JSON object keys are always strings.
     *
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeStringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'http' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('StorageClient must not be serialized; it holds a credentialed transport.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('StorageClient must not be unserialized; it holds a credentialed transport.');
    }
}
