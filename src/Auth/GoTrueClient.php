<?php

declare(strict_types=1);

namespace Supabase\Auth;

use Supabase\Http\Transport;

final class GoTrueClient
{
    private readonly AuthHttp $http;

    public function __construct(Transport $transport, private readonly string $baseUrl)
    {
        $this->http = new AuthHttp($transport);
    }

    /** Returns the base URL (used for OAuth sign-in URL construction). */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function signUp(string $email, string $password, array $options = []): ?Session
    {
        $data = $this->http->request('POST', '/signup', [
            'body' => ['email' => $email, 'password' => $password] + $options,
        ]);

        return isset($data['access_token']) ? Session::fromArray($data) : null;
    }

    public function signInWithPassword(string $email, string $password): Session
    {
        $data = $this->http->request('POST', '/token?grant_type=password', [
            'body' => ['email' => $email, 'password' => $password],
        ]);

        return Session::fromArray($data);
    }

    public function getUser(string $jwt): User
    {
        $data = $this->http->request('GET', '/user', [
            'headers' => ['Authorization' => 'Bearer ' . $jwt],
        ]);

        return User::fromArray($data);
    }

    public function refreshSession(string $refreshToken): Session
    {
        $data = $this->http->request('POST', '/token?grant_type=refresh_token', [
            'body' => ['refresh_token' => $refreshToken],
        ]);

        return Session::fromArray($data);
    }

    public function signOut(string $jwt): void
    {
        $this->http->request('POST', '/logout', [
            'headers' => ['Authorization' => 'Bearer ' . $jwt],
        ]);
    }
}
