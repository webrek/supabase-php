<?php

declare(strict_types=1);

namespace Supabase\Auth;

final class AdminClient
{
    public function __construct(private readonly AuthHttp $http)
    {
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createUser(array $attributes): User
    {
        return User::fromArray($this->http->request('POST', '/admin/users', ['body' => $attributes]));
    }

    public function getUserById(string $id): User
    {
        return User::fromArray($this->http->request('GET', '/admin/users/' . rawurlencode($id)));
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function updateUserById(string $id, array $attributes): User
    {
        return User::fromArray(
            $this->http->request('PUT', '/admin/users/' . rawurlencode($id), ['body' => $attributes]),
        );
    }

    public function deleteUser(string $id, bool $shouldSoftDelete = false): void
    {
        $this->http->request('DELETE', '/admin/users/' . rawurlencode($id), [
            'body' => ['should_soft_delete' => $shouldSoftDelete],
        ]);
    }
}
