<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\SimpleCache\CacheInterface;
use Supabase\Auth\Claims;
use Supabase\Auth\Jwks;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\AuthException;
use Supabase\Tests\Support\ArrayCache;
use Supabase\Tests\Support\JwtFixtures;
use Supabase\Tests\Support\MockClient;

beforeEach(fn () => Jwks::clearProcessCache());

function claimsClient(MockClient $http, ?CacheInterface $cache = null, ?string $jwtSecret = null, int $ttl = 600): Client
{
    $factory = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
        jwksCache: $cache,
        jwksCacheTtl: $ttl,
        jwtSecret: $jwtSecret,
    ));
}

/**
 * @param list<array<string, mixed>> $keys
 */
function jwksResponse(array $keys): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['keys' => $keys]));
}

// ---------------------------------------------------------------------------
// Local verification against the JWKS
// ---------------------------------------------------------------------------

test('getClaims verifies an ES256 token against the project JWKS and returns typed claims', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256();
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'key-es'], JwtFixtures::payload(time() + 3600), $sign);

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));

    $claims = claimsClient($http)->auth()->getClaims($jwt);

    expect($http->requests)->toHaveCount(1)
        ->and((string) $http->requests[0]->getUri())->toBe('https://demo.supabase.co/auth/v1/.well-known/jwks.json')
        ->and($claims)->toBeInstanceOf(Claims::class)
        ->and($claims->sub)->toBe('user-1')
        ->and($claims->role)->toBe('authenticated')
        ->and($claims->email)->toBe('a@b.com')
        ->and($claims->sessionId)->toBe('sess-1')
        ->and($claims->aud)->toBe('authenticated')
        ->and($claims->iss)->toBe('https://demo.supabase.co/auth/v1')
        ->and($claims->isAnonymous)->toBeFalse()
        ->and($claims->appMetadata)->toBe(['provider' => 'email'])
        ->and($claims->userMetadata)->toBe(['name' => 'Ada'])
        ->and($claims->raw['iat'])->toBe($claims->iat)
        ->and($claims->isExpired())->toBeFalse();
});

test('getClaims verifies an RS256 token', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::rs256();
    $jwt = JwtFixtures::jwt(['alg' => 'RS256', 'kid' => 'key-rs'], JwtFixtures::payload(time() + 60), $sign);

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));

    expect(claimsClient($http)->auth()->getClaims($jwt)->sub)->toBe('user-1');
});

test('getClaims rejects a token whose payload was tampered with', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256();
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() + 3600), $sign);

    [$h, , $s] = explode('.', $jwt);
    $forged = $h . '.' . JwtFixtures::b64url((string) json_encode(JwtFixtures::payload(time() + 3600, ['role' => 'service_role']))) . '.' . $s;

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));

    expect(fn () => claimsClient($http)->auth()->getClaims($forged))
        ->toThrow(AuthException::class, 'signature verification failed');
});

test('getClaims rejects a token signed by a different key with the same kid', function () {
    ['jwk' => $jwk] = JwtFixtures::es256('shared-kid');
    ['sign' => $otherSign] = JwtFixtures::es256('shared-kid');
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'shared-kid'], JwtFixtures::payload(time() + 3600), $otherSign);

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));

    expect(fn () => claimsClient($http)->auth()->getClaims($jwt))->toThrow(AuthException::class);
});

test('getClaims rejects an expired token and a token that is not valid yet', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256();
    $expired = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() - 1), $sign);
    $early = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() + 3600, ['nbf' => time() + 60]), $sign);

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));
    $auth = claimsClient($http)->auth();

    expect(fn () => $auth->getClaims($expired))->toThrow(AuthException::class, 'expired')
        ->and(fn () => $auth->getClaims($early))->toThrow(AuthException::class, 'nbf');
});

test('getClaims rejects malformed tokens and unsupported algorithms', function () {
    $http = new MockClient();
    $auth = claimsClient($http)->auth();
    $none = JwtFixtures::jwt(['alg' => 'none'], JwtFixtures::payload(time() + 60), fn () => 'x');

    expect(fn () => $auth->getClaims('not-a-jwt'))->toThrow(AuthException::class, 'three')
        ->and(fn () => $auth->getClaims('a.b.'))->toThrow(AuthException::class)
        ->and(fn () => $auth->getClaims($none))->toThrow(AuthException::class, 'Unsupported')
        ->and($http->requests)->toBe([]);
});

// ---------------------------------------------------------------------------
// Key lookup, rotation and caching
// ---------------------------------------------------------------------------

test('an unknown kid triggers exactly one JWKS refetch', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256('rotated');
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'rotated'], JwtFixtures::payload(time() + 3600), $sign);

    $http = new MockClient();
    $http->queue(jwksResponse([['kty' => 'EC', 'kid' => 'old']]));   // stale set
    $http->queue(jwksResponse([$jwk]));                              // after rotation

    expect(claimsClient($http)->auth()->getClaims($jwt)->sub)->toBe('user-1')
        ->and($http->requests)->toHaveCount(2);

    $http->queue(jwksResponse([]));
    $http->queue(jwksResponse([]));
    $missing = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'nope'], JwtFixtures::payload(time() + 3600), $sign);
    Jwks::clearProcessCache();

    expect(fn () => claimsClient($http)->auth()->getClaims($missing))->toThrow(AuthException::class, 'kid "nope"')
        ->and($http->requests)->toHaveCount(4);
});

test('the key set is memoised per process across Client instances', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256();
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() + 3600), $sign);

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));

    claimsClient($http)->auth()->getClaims($jwt);
    claimsClient($http)->auth()->getClaims($jwt);
    claimsClient($http)->withAccessToken($jwt)->auth()->getClaims($jwt);

    expect($http->requests)->toHaveCount(1);
});

test('a PSR-16 cache is filled on the first fetch and read back instead of the network', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256();
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() + 3600), $sign);
    $cache = new ArrayCache();

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));
    claimsClient($http, $cache, ttl: 120)->auth()->getClaims($jwt);

    expect($cache->sets)->toHaveCount(1)
        ->and($cache->sets[0][1])->toBe(120)
        ->and($cache->sets[0][0])->toStartWith('supabase_jwks_');

    // A fresh process (memo cleared) with the same cache never touches the network.
    Jwks::clearProcessCache();
    $cold = new MockClient();
    expect(claimsClient($cold, $cache)->auth()->getClaims($jwt)->sub)->toBe('user-1')
        ->and($cold->requests)->toBe([]);
});

test('garbage in the PSR-16 cache is ignored and the set is refetched', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256();
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() + 3600), $sign);
    $cache = new ArrayCache();
    $cache->items['supabase_jwks_' . sha1('https://demo.supabase.co')] = 'corrupt';

    $http = new MockClient();
    $http->queue(jwksResponse([$jwk]));

    expect(claimsClient($http, $cache)->auth()->getClaims($jwt)->sub)->toBe('user-1')
        ->and($http->requests)->toHaveCount(1);
});

test('an invalid JWKS response throws AuthException', function () {
    ['sign' => $sign] = JwtFixtures::es256();
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() + 3600), $sign);

    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"nope":true}'));

    expect(fn () => claimsClient($http)->auth()->getClaims($jwt))->toThrow(AuthException::class, 'JWKS');
});

// ---------------------------------------------------------------------------
// Legacy HS256
// ---------------------------------------------------------------------------

test('an HS256 token is verified locally with jwtSecret and never hits the network', function () {
    $jwt = JwtFixtures::jwt(['alg' => 'HS256'], JwtFixtures::payload(time() + 3600), JwtFixtures::hs256('super-secret'));
    $http = new MockClient();

    $claims = claimsClient($http, jwtSecret: 'super-secret')->auth()->getClaims($jwt);

    expect($claims->sub)->toBe('user-1')
        ->and($http->requests)->toBe([]);
});

test('an HS256 token signed with another secret is rejected', function () {
    $jwt = JwtFixtures::jwt(['alg' => 'HS256'], JwtFixtures::payload(time() + 3600), JwtFixtures::hs256('other'));

    expect(fn () => claimsClient(new MockClient(), jwtSecret: 'super-secret')->auth()->getClaims($jwt))
        ->toThrow(AuthException::class, 'signature verification failed');
});

test('an HS256 token without jwtSecret falls back to /auth/v1/user with the token as bearer', function () {
    $jwt = JwtFixtures::jwt(['alg' => 'HS256'], JwtFixtures::payload(time() + 3600), JwtFixtures::hs256('unknown'));
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"user-1"}'));

    $claims = claimsClient($http)->auth()->getClaims($jwt);

    expect($claims->sub)->toBe('user-1')
        ->and($http->requests)->toHaveCount(1)
        ->and((string) $http->requests[0]->getUri())->toBe('https://demo.supabase.co/auth/v1/user')
        ->and($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer ' . $jwt);

    $http->queue(new Response(401, ['Content-Type' => 'application/json'], '{"msg":"invalid JWT"}'));
    expect(fn () => claimsClient($http)->auth()->getClaims($jwt))->toThrow(AuthException::class);
});

test('ClientOptions redacts jwtSecret in dumps', function () {
    ob_start();
    var_dump(new ClientOptions(jwtSecret: 'HMAC_SECRET'));
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain('HMAC_SECRET')
        ->and($dump)->toContain('***redacted***');
});
