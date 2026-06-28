<?php

declare(strict_types=1);

namespace Supabase\Auth;

use Supabase\Exception\AuthException;
use Supabase\Http\ResponseBody;
use Supabase\Http\Transport;

final class AuthHttp
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param array{body?: array<mixed>|string, headers?: array<string,string>, query?: array<string,scalar>|list<array{0:string,1:string}>} $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $response = $this->transport->request($method, '/auth/v1' . $path, $options);

        if ($response->getStatusCode() >= 400) {
            throw AuthException::fromResponse($response);
        }

        $body = ResponseBody::read($response->getBody());
        if ($body === '') {
            return [];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function __serialize(): array
    {
        throw new \LogicException('AuthHttp must not be serialized; it holds a credentialed transport.');
    }

    /**
     * @param array<string,mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('AuthHttp must not be unserialized; it holds a credentialed transport.');
    }
}
