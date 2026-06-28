<?php

declare(strict_types=1);

namespace Supabase\Storage;

use Psr\Http\Message\StreamInterface;

final class FileApi
{
    public function __construct(
        private readonly StorageHttp $http,
        private readonly string $bucketId,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @param string|StreamInterface $contents
     * @param array{contentType?: string, upsert?: bool} $options
     * @return array<mixed>
     */
    public function upload(string $path, string|StreamInterface $contents, array $options = []): array
    {
        $headers = ['Content-Type' => $options['contentType'] ?? 'application/octet-stream'];
        if (! empty($options['upsert'])) {
            $headers['x-upsert'] = 'true';
        }

        return $this->http->requestJson('POST', $this->objectPath($path), [
            'headers' => $headers,
            'body' => $contents,
        ]);
    }

    public function download(string $path, int $maxBytes = 52_428_800): string
    {
        return $this->http->requestRaw('GET', $this->objectPath($path), [], $maxBytes);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<mixed>
     */
    public function list(string $path = '', array $options = []): array
    {
        return $this->http->requestJson('POST', '/object/list/' . rawurlencode($this->bucketId), [
            'body' => ['prefix' => $path] + $options,
        ]);
    }

    /**
     * @param list<string> $paths
     * @return array<mixed>
     */
    public function remove(array $paths): array
    {
        return $this->http->requestJson('DELETE', '/object/' . rawurlencode($this->bucketId), [
            'body' => ['prefixes' => $paths],
        ]);
    }

    public function move(string $from, string $to): void
    {
        $this->http->requestJson('POST', '/object/move', [
            'body' => ['bucketId' => $this->bucketId, 'sourceKey' => $from, 'destinationKey' => $to],
        ]);
    }

    public function copy(string $from, string $to): void
    {
        $this->http->requestJson('POST', '/object/copy', [
            'body' => ['bucketId' => $this->bucketId, 'sourceKey' => $from, 'destinationKey' => $to],
        ]);
    }

    public function createSignedUrl(string $path, int $expiresIn): string
    {
        $data = $this->http->requestJson('POST', '/object/sign/' . rawurlencode($this->bucketId) . '/' . $this->encodePath($path), [
            'body' => ['expiresIn' => $expiresIn],
        ]);
        $signed = isset($data['signedURL']) && is_string($data['signedURL']) ? $data['signedURL'] : '';

        return rtrim($this->baseUrl, '/') . '/storage/v1' . $signed;
    }

    /**
     * @param list<string> $paths
     * @return array<mixed>
     */
    public function createSignedUrls(array $paths, int $expiresIn): array
    {
        return $this->http->requestJson('POST', '/object/sign/' . rawurlencode($this->bucketId), [
            'body' => ['expiresIn' => $expiresIn, 'paths' => $paths],
        ]);
    }

    /**
     * @return array<mixed>
     */
    public function createSignedUploadUrl(string $path): array
    {
        return $this->http->requestJson('POST', '/object/upload/sign/' . rawurlencode($this->bucketId) . '/' . $this->encodePath($path));
    }

    /**
     * @param string|StreamInterface $contents
     * @param array{contentType?: string} $options
     * @return array<mixed>
     */
    public function uploadToSignedUrl(string $path, string $token, string|StreamInterface $contents, array $options = []): array
    {
        return $this->http->requestJson('PUT', '/object/upload/sign/' . rawurlencode($this->bucketId) . '/' . $this->encodePath($path), [
            'query' => [['token', $token]],
            'headers' => ['Content-Type' => $options['contentType'] ?? 'application/octet-stream'],
            'body' => $contents,
        ]);
    }

    public function getPublicUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/storage/v1/object/public/' . rawurlencode($this->bucketId) . '/' . $this->encodePath($path);
    }

    private function objectPath(string $path): string
    {
        return '/object/' . rawurlencode($this->bucketId) . '/' . $this->encodePath($path);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'bucketId' => $this->bucketId, 'http' => '[redacted]'];
    }
}
