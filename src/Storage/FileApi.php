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
