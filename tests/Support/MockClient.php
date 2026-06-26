<?php

declare(strict_types=1);

namespace Supabase\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MockClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    /** @var list<ResponseInterface> */
    private array $queue = [];

    public function queue(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return array_shift($this->queue)
            ?? throw new \RuntimeException('No queued response in MockClient.');
    }
}
