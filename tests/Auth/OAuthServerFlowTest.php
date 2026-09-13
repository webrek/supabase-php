<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\Pkce;
use Supabase\Auth\Session;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\AuthException;
use Supabase\Tests\Support\MockClient;

function oauthClient(MockClient $http): Client
{
    $factory = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

const SESSION_JSON = '{"access_token":"AT","refresh_token":"RT","token_type":"bearer","expires_in":3600,"user":{"id":"u1","email":"a@b.com"}}';

// ---------------------------------------------------------------------------
// Pkce
// ---------------------------------------------------------------------------

test('Pkce::generate produces an RFC 7636 verifier and its S256 challenge', function () {
    $pkce = Pkce::generate();
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $pkce->verifier, true)), '+/', '-_'), '=');

    expect(strlen($pkce->verifier))->toBe(43)
        ->and($pkce->verifier)->toMatch('/^[A-Za-z0-9\-._~]+$/')
        ->and($pkce->challenge)->toBe($expectedChallenge)
        ->and($pkce->challenge)->toMatch('/^[A-Za-z0-9\-_]{43}$/')
        ->and(Pkce::generate()->verifier)->not->toBe($pkce->verifier);
});

test('Pkce::fromVerifier rebuilds the same challenge and validates the verifier', function () {
    $pkce = Pkce::generate();

    expect(Pkce::fromVerifier($pkce->verifier)->challenge)->toBe($pkce->challenge)
        ->and(fn () => Pkce::fromVerifier('too-short'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => Pkce::fromVerifier(str_repeat('a', 129)))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => Pkce::fromVerifier(str_repeat('a', 42) . '!'))->toThrow(\InvalidArgumentException::class);
});

test('Pkce redacts the verifier in dumps and cannot be serialized', function () {
    $pkce = Pkce::generate();

    ob_start();
    var_dump($pkce);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain($pkce->verifier)
        ->and($dump)->toContain('***redacted***')
        ->and($dump)->toContain($pkce->challenge)
        ->and(fn () => serialize($pkce))->toThrow(\LogicException::class);
});

// ---------------------------------------------------------------------------
// OAuth with PKCE
// ---------------------------------------------------------------------------

test('getOAuthSignInUrl adds the PKCE challenge and method when a Pkce pair is given', function () {
    $http = new MockClient();
    $pkce = Pkce::fromVerifier(str_repeat('v', 43));

    $url = oauthClient($http)->auth()->getOAuthSignInUrl('github', ['redirect_to' => 'https://app.test/cb'], $pkce);
    $plain = oauthClient($http)->auth()->getOAuthSignInUrl('github', ['redirect_to' => 'https://app.test/cb']);

    expect($url)->toBe(
        'https://demo.supabase.co/auth/v1/authorize?provider=github&redirect_to=https%3A%2F%2Fapp.test%2Fcb'
        . '&code_challenge=' . $pkce->challenge . '&code_challenge_method=s256'
    )
        ->and($plain)->not->toContain('code_challenge')
        ->and($http->lastRequest)->toBeNull();
});

test('exchangeCodeForSession posts the code and verifier to the pkce grant and returns a Session', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], SESSION_JSON));

    $session = oauthClient($http)->auth()->exchangeCodeForSession('CODE-123', str_repeat('v', 43));

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/auth/v1/token?grant_type=pkce')
        ->and((string) $request->getBody())->toBe('{"auth_code":"CODE-123","code_verifier":"' . str_repeat('v', 43) . '"}')
        ->and($session)->toBeInstanceOf(Session::class)
        ->and($session->accessToken)->toBe('AT')
        ->and($session->user->email)->toBe('a@b.com');
});

test('exchangeCodeForSession surfaces a rejected code as AuthException', function () {
    $http = new MockClient();
    $http->queue(new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant","error_description":"invalid flow state"}'));

    expect(fn () => oauthClient($http)->auth()->exchangeCodeForSession('BAD', str_repeat('v', 43)))
        ->toThrow(AuthException::class, 'invalid flow state');
});

// ---------------------------------------------------------------------------
// ID token
// ---------------------------------------------------------------------------

test('signInWithIdToken posts provider, id_token and options to the id_token grant', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], SESSION_JSON));

    $session = oauthClient($http)->auth()->signInWithIdToken('google', 'ID-TOKEN', ['nonce' => 'n1']);

    $request = $http->lastRequest;
    \assert($request !== null);
    expect((string) $request->getUri())->toBe('https://demo.supabase.co/auth/v1/token?grant_type=id_token')
        ->and((string) $request->getBody())->toBe('{"provider":"google","id_token":"ID-TOKEN","nonce":"n1"}')
        ->and($session->user->id)->toBe('u1');
});

test('signInWithIdToken errors do not leak the id_token in the exception body', function () {
    $http = new MockClient();
    $http->queue(new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant","id_token":"ID-TOKEN-ECHO"}'));

    $caught = null;
    try {
        oauthClient($http)->auth()->signInWithIdToken('apple', 'ID-TOKEN');
    } catch (AuthException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    \assert($caught instanceof AuthException);
    expect((string) $caught->getResponseBody())->not->toContain('ID-TOKEN-ECHO');
});
