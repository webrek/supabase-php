<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

/*
 * Exact request bodies for the GoTrue calls whose payload was only checked
 * loosely (a dropped body key survived mutation), plus URL building with a
 * trailing slash on the project URL.
 */

function shapeClient(MockClient $http, string $url = 'https://demo.supabase.co'): Client
{
    $factory = new Psr17Factory();

    return new Client($url, 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

const SESSION_BODY = '{"access_token":"AT","refresh_token":"RT","token_type":"bearer","user":{"id":"u1"}}';

test('signUp sends email, password and the extra options in the body', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], SESSION_BODY));

    shapeClient($http)->auth()->signUp('a@b.com', 'pw', ['data' => ['name' => 'Ada']]);

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getBody())->toBe('{"email":"a@b.com","password":"pw","data":{"name":"Ada"}}');
});

test('verifyOtp, updateUser and resend send exactly the given payload', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], SESSION_BODY));
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"u1"}'));
    $http->queue(new Response(200, [], '{}'));
    $auth = shapeClient($http)->auth();

    $auth->verifyOtp(['email' => 'a@b.com', 'token' => '123456', 'type' => 'email']);
    $auth->updateUser('USER_JWT', ['password' => 'new-pw', 'data' => ['x' => 1]]);
    $auth->resend(['type' => 'signup', 'email' => 'a@b.com']);

    expect($http->requests)->toHaveCount(3)
        ->and((string) $http->requests[0]->getBody())->toBe('{"email":"a@b.com","token":"123456","type":"email"}')
        ->and((string) $http->requests[1]->getBody())->toBe('{"password":"new-pw","data":{"x":1}}')
        ->and($http->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT')
        ->and((string) $http->requests[2]->getBody())->toBe('{"type":"signup","email":"a@b.com"}');
});

test('getOAuthSignInUrl does not double the slash when the project URL ends with one', function () {
    $url = shapeClient(new MockClient(), 'https://demo.supabase.co/')->auth()->getOAuthSignInUrl('github');

    expect($url)->toBe('https://demo.supabase.co/auth/v1/authorize?provider=github');
});
