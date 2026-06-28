<?php

declare(strict_types=1);

namespace Supabase\Postgrest;

use Psr\Http\Message\ResponseInterface;
use Supabase\Exception\PostgrestException;
use Supabase\Http\ResponseBody;
use Supabase\Http\Transport;

class FilterBuilder
{
    /** @var list<array{0:string,1:string}> */
    private array $params = [];

    /** @var array<string,string> */
    private array $headers = [];

    private bool $maybeSingle = false;

    /**
     * @param array<mixed>|null $body
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $resourcePath,
        private readonly string $method,
        private readonly string $schema,
        private readonly ?array $body = null,
    ) {
        if ($this->schema !== '' && $this->schema !== 'public') {
            $header = in_array($this->method, ['GET', 'HEAD'], true) ? 'Accept-Profile' : 'Content-Profile';
            $this->headers[$header] = $this->schema;
        }
    }

    public function select(string $columns = '*'): static
    {
        $this->addParam('select', $columns);
        if (! in_array($this->method, ['GET', 'HEAD'], true)) {
            $this->mergePrefer('return=representation');
        }

        return $this;
    }

    /**
     * @return array<mixed>|null
     */
    public function execute(): array|null
    {
        $response = $this->send($this->method);

        return $this->decode($response);
    }

    protected function addParam(string $key, string $value): void
    {
        $this->params[] = [$key, $value];
    }

    protected function mergePrefer(string $value): void
    {
        $existing = $this->headers['Prefer'] ?? '';
        $this->headers['Prefer'] = $existing === '' ? $value : $existing . ',' . $value;
    }

    protected function markMaybeSingle(): void
    {
        $this->maybeSingle = true;
    }

    /**
     * @return array{headers: array<string,string>, query: list<array{0:string,1:string}>, body?: array<mixed>}
     */
    protected function buildOptions(): array
    {
        $options = [
            'headers' => $this->headers,
            'query' => $this->params,
        ];
        if ($this->body !== null) {
            $options['body'] = $this->body;
        }

        return $options;
    }

    protected function send(string $method): ResponseInterface
    {
        return $this->transport->request($method, $this->resourcePath, $this->buildOptions());
    }

    /**
     * @return array<mixed>|null
     */
    protected function decode(ResponseInterface $response): array|null
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw PostgrestException::fromResponse($response);
        }

        $body = ResponseBody::read($response->getBody());
        if ($body === '') {
            return null;
        }

        /** @var array<mixed>|null $decoded */
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return null;
        }

        if ($this->maybeSingle) {
            /** @var list<array<string,mixed>> $decoded */
            return $decoded[0] ?? null;
        }

        return $decoded;
    }
}
