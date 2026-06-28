<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\User;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function adminExtra(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'SERVICE_ROLE', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

test('listUsers gets the paginated endpoint and maps to User objects', function () {
    $http = new MockClient();
    $http->queue(new Response(
        200,
        ['Content-Type' => 'application/json'],
        '{"users":[{"id":"u1","email":"a@b.com"},{"id":"u2","email":"c@d.com"}],"aud":"authenticated"}'
    ));

    $users = adminExtra($http)->auth()->admin()->listUsers(2, 25);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('GET')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/admin/users?page=2&per_page=25')
        ->and($users)->toHaveCount(2)
        ->and($users[0])->toBeInstanceOf(User::class)
        ->and($users[0]->id)->toBe('u1')
        ->and($users[1]->email)->toBe('c@d.com');
});

test('listUsers returns an empty list when there are no users', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"users":[]}'));
    expect(adminExtra($http)->auth()->admin()->listUsers())->toBe([]);
});

test('inviteUserByEmail posts to /invite and returns a User', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u1","email":"a@b.com"}'));

    $user = adminExtra($http)->auth()->admin()->inviteUserByEmail('a@b.com', ['data' => ['role' => 'member']]);

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/invite')
        ->and((string) $http->lastRequest->getBody())->toBe('{"email":"a@b.com","data":{"role":"member"}}')
        ->and($user)->toBeInstanceOf(User::class)
        ->and($user->id)->toBe('u1');
});

test('generateLink posts to /admin/generate_link and returns the decoded payload', function () {
    $http = new MockClient();
    $http->queue(new Response(
        200,
        ['Content-Type' => 'application/json'],
        '{"action_link":"https://x/verify?token=AAA","user":{"id":"u1"}}'
    ));

    $result = adminExtra($http)->auth()->admin()->generateLink(['type' => 'magiclink', 'email' => 'a@b.com']);

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/admin/generate_link')
        ->and((string) $http->lastRequest->getBody())->toBe('{"type":"magiclink","email":"a@b.com"}')
        ->and($result['action_link'])->toBe('https://x/verify?token=AAA');
});
