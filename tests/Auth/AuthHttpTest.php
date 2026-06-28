<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\AuthHttp;
use Supabase\Exception\AuthException;
use Supabase\Http\Transport;
use Supabase\Tests\Support\MockClient;

function authHttp(MockClient $client): AuthHttp
{
    $factory = new Psr17Factory();
    $transport = new Transport(
        'https://demo.supabase.co',
        ['apikey' => 'ANON', 'Authorization' => 'Bearer ANON'],
        $client,
        $factory,
        $factory,
    );

    return new AuthHttp($transport);
}

test('request prepends /auth/v1, sends body, and returns decoded array', function () {
    $client = new MockClient();
    $client->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"x"}'));

    $data = authHttp($client)->request('POST', '/signup', ['body' => ['email' => 'a@b.com']]);

    \assert($client->lastRequest !== null);
    expect($client->lastRequest->getMethod())->toBe('POST')
        ->and((string) $client->lastRequest->getUri())->toBe('https://demo.supabase.co/auth/v1/signup')
        ->and((string) $client->lastRequest->getBody())->toBe('{"email":"a@b.com"}')
        ->and($data)->toBe(['id' => 'x']);
});

test('request throws AuthException on a non-2xx response', function () {
    $client = new MockClient();
    $client->queue(new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid","error_description":"bad creds"}'));

    expect(fn () => authHttp($client)->request('POST', '/token?grant_type=password', ['body' => []]))
        ->toThrow(AuthException::class);
});

test('request returns [] for an empty body', function () {
    $client = new MockClient();
    $client->queue(new Response(204, [], ''));
    expect(authHttp($client)->request('POST', '/logout'))->toBe([]);
});
