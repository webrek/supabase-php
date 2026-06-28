<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\AdminClient;
use Supabase\Auth\User;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function adminClient(MockClient $http): Client
{
    $f = new Psr17Factory();

    // Admin requires the service_role key — passed as the apiKey here.
    return new Client('https://demo.supabase.co', 'SERVICE_ROLE', new ClientOptions(
        httpClient: $http,
        requestFactory: $f,
        streamFactory: $f,
    ));
}

test('admin() returns a memoized AdminClient', function () {
    $c = adminClient(new MockClient());
    expect($c->auth()->admin())->toBeInstanceOf(AdminClient::class)
        ->and($c->auth()->admin())->toBe($c->auth()->admin());
});

test('createUser posts to /admin/users and returns a User', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u1","email":"a@b.com"}'));

    $user = adminClient($http)->auth()->admin()->createUser(['email' => 'a@b.com', 'password' => 'pw']);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/admin/users')
        ->and((string) $http->lastRequest->getBody())->toBe('{"email":"a@b.com","password":"pw"}')
        ->and($http->lastRequest->getHeaderLine('apikey'))->toBe('SERVICE_ROLE')
        ->and($user)->toBeInstanceOf(User::class)
        ->and($user->id)->toBe('u1');
});

test('getUserById gets /admin/users/{id} url-encoded', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u 1"}'));

    $user = adminClient($http)->auth()->admin()->getUserById('u 1');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('GET')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/admin/users/u%201')
        ->and($user->id)->toBe('u 1');
});

test('updateUserById PUTs to /admin/users/{id}', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u1","email":"new@b.com"}'));

    $user = adminClient($http)->auth()->admin()->updateUserById('u1', ['email' => 'new@b.com']);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('PUT')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/admin/users/u1')
        ->and((string) $http->lastRequest->getBody())->toBe('{"email":"new@b.com"}')
        ->and($user->email)->toBe('new@b.com');
});

test('deleteUser DELETEs /admin/users/{id} and returns void', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{}'));

    adminClient($http)->auth()->admin()->deleteUser('u1');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('DELETE')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/admin/users/u1')
        ->and((string) $http->lastRequest->getBody())->toBe('{"should_soft_delete":false}');
});
