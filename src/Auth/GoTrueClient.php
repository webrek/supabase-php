<?php

declare(strict_types=1);

namespace Supabase\Auth;

use Supabase\Http\Transport;

final class GoTrueClient
{
    private readonly AuthHttp $http;

    private ?AdminClient $admin = null;

    public function __construct(Transport $transport, private readonly string $baseUrl)
    {
        $this->http = new AuthHttp($transport);
    }

    public function admin(): AdminClient
    {
        return $this->admin ??= new AdminClient($this->http);
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

    /**
     * @param array<string,mixed> $params
     */
    public function signInWithOtp(array $params): void
    {
        $this->http->request('POST', '/otp', ['body' => $params]);
    }

    /**
     * @param array<string,mixed> $params
     */
    public function verifyOtp(array $params): Session
    {
        return Session::fromArray($this->http->request('POST', '/verify', ['body' => $params]));
    }

    /**
     * @param array<string,mixed> $options
     */
    public function resetPasswordForEmail(string $email, array $options = []): void
    {
        $this->http->request('POST', '/recover', ['body' => ['email' => $email] + $options]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function updateUser(string $jwt, array $attributes): User
    {
        $data = $this->http->request('PUT', '/user', [
            'headers' => ['Authorization' => 'Bearer ' . $jwt],
            'body' => $attributes,
        ]);

        return User::fromArray($data);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function getOAuthSignInUrl(string $provider, array $options = []): string
    {
        // RFC 3986 so spaces encode as %20 (not +), which OAuth servers expect in redirect_to.
        $query = http_build_query(['provider' => $provider] + $options, encoding_type: PHP_QUERY_RFC3986);

        return rtrim($this->baseUrl, '/') . '/auth/v1/authorize?' . $query;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function resend(array $params): void
    {
        $this->http->request('POST', '/resend', ['body' => $params]);
    }
}
