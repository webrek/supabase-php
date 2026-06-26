<?php

declare(strict_types=1);

namespace Supabase;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

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
        public ?string $accessToken = null,
    ) {
    }
}
