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

    /**
     * @return list<User>
     */
    public function listUsers(int $page = 1, int $perPage = 50): array
    {
        $data = $this->http->request('GET', '/admin/users', [
            'query' => [['page', (string) $page], ['per_page', (string) $perPage]],
        ]);

        $rows = isset($data['users']) && is_array($data['users']) ? $data['users'] : [];

        $users = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string,mixed> $row */
                $users[] = User::fromArray($row);
            }
        }

        return $users;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function inviteUserByEmail(string $email, array $options = []): User
    {
        return User::fromArray($this->http->request('POST', '/invite', ['body' => ['email' => $email] + $options]));
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function generateLink(array $params): array
    {
        return $this->http->request('POST', '/admin/generate_link', ['body' => $params]);
    }
}
