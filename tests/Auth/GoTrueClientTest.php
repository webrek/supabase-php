<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\Session;
use Supabase\Auth\User;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function authClient(MockClient $http): Client
{
    $f = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $f,
        streamFactory: $f,
    ));
}

test('signInWithPassword posts to the token endpoint and returns a Session', function () {
    $http = new MockClient();
    $http->queue(new Response(
        200,
        ['Content-Type' => 'application/json'],
        '{"access_token":"AT","refresh_token":"RT","token_type":"bearer","expires_in":3600,"user":{"id":"u1","email":"a@b.com"}}'
    ));

    $session = authClient($http)->auth()->signInWithPassword('a@b.com', 'pw');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/token?grant_type=password')
        ->and((string) $http->lastRequest->getBody())->toBe('{"email":"a@b.com","password":"pw"}')
        ->and($session)->toBeInstanceOf(Session::class)
        ->and($session->accessToken)->toBe('AT')
        ->and($session->user->email)->toBe('a@b.com');
});

test('signUp returns a Session when tokens are present', function () {
    $http = new MockClient();
    $http->queue(new Response(
        200,
        ['Content-Type' => 'application/json'],
        '{"access_token":"AT","refresh_token":"RT","token_type":"bearer","user":{"id":"u1"}}'
    ));

    $res = authClient($http)->auth()->signUp('a@b.com', 'pw');
    expect($res)->toBeInstanceOf(Session::class);
    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/signup');
});

test('signUp returns null when email confirmation is required (no tokens)', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u1","email":"a@b.com"}'));

    expect(authClient($http)->auth()->signUp('a@b.com', 'pw'))->toBeNull();
});

test('getUser sends the user JWT as Bearer and returns a User', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u1","email":"a@b.com"}'));

    $user = authClient($http)->auth()->getUser('USER_JWT');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('GET')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/user')
        ->and($http->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT')
        ->and($http->lastRequest->getHeaderLine('apikey'))->toBe('ANON')
        ->and($user)->toBeInstanceOf(User::class)
        ->and($user->id)->toBe('u1');
});

test('refreshSession posts the refresh token and returns a Session', function () {
    $http = new MockClient();
    $http->queue(new Response(
        200,
        ['Content-Type' => 'application/json'],
        '{"access_token":"AT2","refresh_token":"RT2","token_type":"bearer","user":{"id":"u1"}}'
    ));

    $session = authClient($http)->auth()->refreshSession('RT');

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/token?grant_type=refresh_token')
        ->and((string) $http->lastRequest->getBody())->toBe('{"refresh_token":"RT"}')
        ->and($session->accessToken)->toBe('AT2');
});

test('signOut posts to logout with the user JWT and returns void', function () {
    $http = new MockClient();
    $http->queue(new Response(204, [], ''));

    authClient($http)->auth()->signOut('USER_JWT');

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/logout')
        ->and($http->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT');
});
