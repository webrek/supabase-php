<?php

declare(strict_types=1);

namespace Supabase\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\Session;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\AuthException;
use Supabase\Tests\Support\MockClient;

/**
 * @param array<string, string> $headers
 */
function contextClient(MockClient $http, string $schema = 'public', array $headers = []): Client
{
    $factory = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
        headers: $headers,
        schema: $schema,
    ));
}

function sessionExpiringAt(?int $expiresAt): Session
{
    return Session::fromArray([
        'access_token' => 'USER_JWT',
        'refresh_token' => 'REFRESH',
        'expires_at' => $expiresAt,
        'user' => ['id' => 'u1'],
    ]);
}

function refreshedSessionJson(): string
{
    return '{"access_token":"NEW_JWT","refresh_token":"NEW_REFRESH","token_type":"bearer","expires_in":3600,"expires_at":'
        . (time() + 3600) . ',"user":{"id":"u1"}}';
}

// ---------------------------------------------------------------------------
// withAccessToken()
// ---------------------------------------------------------------------------

test('withAccessToken authenticates the sibling as the user and leaves the original unchanged', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    $anon = contextClient($http);
    $asUser = $anon->withAccessToken('USER_JWT');

    $asUser->from('todos')->select()->execute();
    $anon->from('todos')->select()->execute();

    expect($http->requests)->toHaveCount(2)
        ->and($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT')
        ->and($http->requests[0]->getHeaderLine('apikey'))->toBe('ANON')
        ->and($http->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer ANON')
        ->and($asUser)->not->toBe($anon)
        ->and($asUser->auth())->not->toBe($anon->auth());
});

test('withAccessToken(null) reverts to the apikey as bearer', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{}'));

    contextClient($http)->withAccessToken('USER_JWT')->withAccessToken(null)
        ->getTransport()->request('GET', '/x');

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer ANON');
});

test('withAccessToken rejects an empty token', function () {
    $client = contextClient(new MockClient());

    expect(fn () => $client->withAccessToken(''))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $client->withAccessToken('   '))->toThrow(\InvalidArgumentException::class);
});

test('the sibling keeps the schema, custom headers and the same HTTP client', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    contextClient($http, schema: 'tenant', headers: ['X-Custom' => 'yes'])
        ->withAccessToken('USER_JWT')
        ->from('todos')->select()->execute();

    // lastRequest being set proves the sibling reused this very MockClient.
    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getHeaderLine('Accept-Profile'))->toBe('tenant')
        ->and($request->getHeaderLine('X-Custom'))->toBe('yes')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT');
});

// ---------------------------------------------------------------------------
// withSession()
// ---------------------------------------------------------------------------

test('withSession uses the session token without refreshing while it is valid', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{}'));
    $seen = [];

    contextClient($http)
        ->withSession(sessionExpiringAt(time() + 3600), function (Session $s) use (&$seen): void {
            $seen[] = $s;
        })
        ->getTransport()->request('GET', '/x');

    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT')
        ->and($seen)->toBe([]);
});

test('withSession refreshes an expired session, reports it and authenticates with the new token', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], refreshedSessionJson()));
    $http->queue(new Response(200, [], '{}'));
    $seen = [];

    contextClient($http)
        ->withSession(sessionExpiringAt(time() - 10), function (Session $s) use (&$seen): void {
            $seen[] = $s;
        })
        ->getTransport()->request('GET', '/x');

    expect($http->requests)->toHaveCount(2);

    $refresh = $http->requests[0];
    expect($refresh->getMethod())->toBe('POST')
        ->and((string) $refresh->getUri())->toBe('https://demo.supabase.co/auth/v1/token?grant_type=refresh_token')
        ->and((string) $refresh->getBody())->toBe('{"refresh_token":"REFRESH"}')
        ->and($refresh->getHeaderLine('Authorization'))->toBe('Bearer ANON')
        ->and($http->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer NEW_JWT')
        ->and($seen)->toHaveCount(1);

    $fresh = $seen[0] ?? null;
    \assert($fresh instanceof Session);
    expect($fresh->accessToken)->toBe('NEW_JWT')
        ->and($fresh->refreshToken)->toBe('NEW_REFRESH');
});

test('withSession refreshes a session that expires within the margin', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], refreshedSessionJson()));
    $http->queue(new Response(200, [], '{}'));

    contextClient($http)
        ->withSession(sessionExpiringAt(time() + 10), expiryMargin: 30)
        ->getTransport()->request('GET', '/x');

    expect($http->requests)->toHaveCount(2)
        ->and($http->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer NEW_JWT');
});

test('withSession does not refresh a session with an unknown expiry', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{}'));

    contextClient($http)->withSession(sessionExpiringAt(null))->getTransport()->request('GET', '/x');

    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT');
});

test('withSession propagates a failed refresh as AuthException and skips the callback', function () {
    $http = new MockClient();
    $http->queue(new Response(
        400,
        ['Content-Type' => 'application/json'],
        '{"error":"invalid_grant","error_description":"Invalid Refresh Token"}'
    ));
    $seen = [];

    $bind = fn () => contextClient($http)->withSession(sessionExpiringAt(time() - 10), function (Session $s) use (&$seen): void {
        $seen[] = $s;
    });

    expect($bind)->toThrow(AuthException::class)
        ->and($seen)->toBe([]);
});
