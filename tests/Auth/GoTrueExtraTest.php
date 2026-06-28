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

function extraClient(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

test('signInWithOtp posts to /otp and returns void', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{}'));
    // signInWithOtp returns void; PHPStan max forbids capturing a void result, so we assert
    // the request shape (the void return is enforced by the type system at compile time).
    extraClient($http)->auth()->signInWithOtp(['email' => 'a@b.com']);
    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/otp')
        ->and((string) $http->lastRequest->getBody())->toBe('{"email":"a@b.com"}');
});

test('verifyOtp posts to /verify and returns a Session', function () {
    $http = new MockClient();
    $http->queue(new Response(
        200,
        ['Content-Type' => 'application/json'],
        '{"access_token":"AT","refresh_token":"RT","token_type":"bearer","user":{"id":"u1"}}'
    ));
    $session = extraClient($http)->auth()->verifyOtp(['email' => 'a@b.com', 'token' => '123456', 'type' => 'email']);
    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/verify')
        ->and($session)->toBeInstanceOf(Session::class)
        ->and($session->accessToken)->toBe('AT');
});

test('resetPasswordForEmail posts to /recover and returns void', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{}'));
    extraClient($http)->auth()->resetPasswordForEmail('a@b.com');
    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/recover')
        ->and((string) $http->lastRequest->getBody())->toBe('{"email":"a@b.com"}');
});

test('updateUser PUTs to /user with the JWT and returns a User', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u1","email":"new@b.com"}'));
    $user = extraClient($http)->auth()->updateUser('USER_JWT', ['email' => 'new@b.com']);
    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('PUT')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/user')
        ->and($http->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT')
        ->and($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('new@b.com');
});

test('getOAuthSignInUrl builds the authorize URL without an HTTP call', function () {
    $http = new MockClient(); // nothing queued — must not be used
    $url = extraClient($http)->auth()->getOAuthSignInUrl('github', ['redirect_to' => 'https://app.test/cb']);
    expect($url)->toBe('https://demo.supabase.co/auth/v1/authorize?provider=github&redirect_to=https%3A%2F%2Fapp.test%2Fcb')
        ->and($http->lastRequest)->toBeNull();
});

test('resend posts to /resend and returns void', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{}'));
    extraClient($http)->auth()->resend(['type' => 'signup', 'email' => 'a@b.com']);
    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/resend');
});
