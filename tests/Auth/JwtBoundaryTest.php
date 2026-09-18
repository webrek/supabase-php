<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\Claims;
use Supabase\Auth\Jwks;
use Supabase\Auth\JwtVerifier;
use Supabase\Auth\Pkce;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\AuthException;
use Supabase\Tests\Support\ArrayCache;
use Supabase\Tests\Support\JwtFixtures;
use Supabase\Tests\Support\MockClient;

/*
 * Boundary and malformed-input cases behind the Auth mutation survivors: each
 * test pins a comparison, a cast or a defensive branch that a mutant flipped
 * without any existing test noticing.
 */

beforeEach(fn () => Jwks::clearProcessCache());

function boundaryClient(MockClient $http): Client
{
    $factory = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

// ---------------------------------------------------------------------------
// Time claims
// ---------------------------------------------------------------------------

test('assertTimeClaims treats exp == now as expired and exp == now + 1 as valid', function () {
    expect(fn () => JwtVerifier::assertTimeClaims(['exp' => 1000], now: 1000))->toThrow(AuthException::class, 'expired');
    JwtVerifier::assertTimeClaims(['exp' => 1001], now: 1000);
    JwtVerifier::assertTimeClaims(['exp' => '1001'], now: 1000); // numeric strings are accepted
    expect(true)->toBeTrue();
});

test('assertTimeClaims accepts nbf == now and rejects nbf == now + 1', function () {
    JwtVerifier::assertTimeClaims(['exp' => 2000, 'nbf' => 1000], now: 1000);
    JwtVerifier::assertTimeClaims(['exp' => 2000, 'nbf' => 'not-a-number'], now: 1000);
    expect(fn () => JwtVerifier::assertTimeClaims(['exp' => 2000, 'nbf' => 1001], now: 1000))
        ->toThrow(AuthException::class, 'nbf');
});

test('assertTimeClaims uses the wall clock only when no reference time is given', function () {
    JwtVerifier::assertTimeClaims(['exp' => time() + 5]);
    expect(fn () => JwtVerifier::assertTimeClaims(['exp' => time() - 5]))->toThrow(AuthException::class, 'expired');
});

// ---------------------------------------------------------------------------
// Token and key structure
// ---------------------------------------------------------------------------

test('decode rejects an empty signature segment before looking at the header', function () {
    expect(fn () => JwtVerifier::decode('a.b.'))->toThrow(AuthException::class, 'empty signature');
});

test('verifyWithJwk rejects EC keys on other curves and RSA/EC keys under the wrong algorithm', function () {
    ['jwk' => $ec, 'sign' => $sign] = JwtFixtures::es256();
    ['jwk' => $rsa] = JwtFixtures::rs256();
    unset($ec['alg'], $rsa['alg']); // no alg hint: the kty / crv checks must catch it on their own
    $input = 'h.p';
    $sig = $sign($input);

    expect(fn () => JwtVerifier::verifyWithJwk($input, $sig, 'ES256', ['crv' => 'P-384'] + $ec))
        ->toThrow(AuthException::class, 'P-256')
        ->and(fn () => JwtVerifier::verifyWithJwk($input, $sig, 'ES256', $rsa))
        ->toThrow(AuthException::class, 'kty "EC"')
        ->and(fn () => JwtVerifier::verifyWithJwk($input, $sig, 'RS256', $ec))
        ->toThrow(AuthException::class, 'kty "RSA"')
        ->and(fn () => JwtVerifier::verifyWithJwk($input, $sig, 'ES256', ['x' => ''] + $ec))
        ->toThrow(AuthException::class, 'missing "x"')
        ->and(fn () => JwtVerifier::verifyWithJwk($input, $sig, 'ES256', ['y' => JwtFixtures::b64url(str_repeat("\x01", 31))] + $ec))
        ->toThrow(AuthException::class, '32 bytes');
});

test('RS256 verifies with a 960-bit key, whose RSAPublicKey is exactly 128 bytes and needs a long-form DER length', function () {
    $key = openssl_pkey_new(['private_key_bits' => 960, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    \assert($key !== false);
    $details = openssl_pkey_get_details($key);
    \assert($details !== false && is_array($details['rsa']) && is_string($details['rsa']['n']) && is_string($details['rsa']['e']));
    $jwk = ['kty' => 'RSA', 'kid' => 'small', 'n' => JwtFixtures::b64url($details['rsa']['n']), 'e' => JwtFixtures::b64url($details['rsa']['e'])];
    $jwt = JwtFixtures::jwt(['alg' => 'RS256', 'kid' => 'small'], JwtFixtures::payload(time() + 60), static function (string $input) use ($key): string {
        openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
        \assert(is_string($sig));

        return $sig;
    });

    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['keys' => [$jwk]])));

    expect(strlen($details['rsa']['n']))->toBe(120)
        ->and(boundaryClient($http)->auth()->getClaims($jwt)->sub)->toBe('user-1');
});

test('getClaims rejects an ES256 token whose kid is missing, empty or not a string', function () {
    ['sign' => $sign] = JwtFixtures::es256();
    $auth = boundaryClient(new MockClient())->auth();
    $payload = JwtFixtures::payload(time() + 60);

    expect(fn () => $auth->getClaims(JwtFixtures::jwt(['alg' => 'ES256'], $payload, $sign)))->toThrow(AuthException::class, 'kid')
        ->and(fn () => $auth->getClaims(JwtFixtures::jwt(['alg' => 'ES256', 'kid' => ''], $payload, $sign)))->toThrow(AuthException::class, 'kid')
        ->and(fn () => $auth->getClaims(JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 7], $payload, $sign)))->toThrow(AuthException::class, 'kid');
});

// ---------------------------------------------------------------------------
// Claims
// ---------------------------------------------------------------------------

test('Claims::fromArray tolerates malformed claim types instead of trusting them', function () {
    $claims = Claims::fromArray([
        'sub' => 42,                       // scalar: kept as string
        'role' => ['not', 'a', 'string'],  // dropped
        'email' => '',                     // empty: dropped
        'phone' => 12345,                  // non-string: dropped
        'session_id' => null,
        'aud' => ['authenticated', 7, null, 'admin'],
        'iss' => false,
        'exp' => '1700000000',             // numeric string: cast
        'iat' => 'yesterday',              // dropped
        'is_anonymous' => 'true',          // only boolean true counts
        'app_metadata' => 'nope',          // non-array: empty
        'user_metadata' => [0 => 'zero', 'name' => 'Ada'],
    ]);

    expect($claims->sub)->toBe('42')
        ->and($claims->role)->toBeNull()
        ->and($claims->email)->toBeNull()
        ->and($claims->phone)->toBeNull()
        ->and($claims->sessionId)->toBeNull()
        ->and($claims->aud)->toBe(['authenticated', 'admin'])
        ->and($claims->iss)->toBeNull()
        ->and($claims->exp)->toBe(1700000000)
        ->and($claims->iat)->toBeNull()
        ->and($claims->isAnonymous)->toBeFalse()
        ->and($claims->appMetadata)->toBe([])
        ->and($claims->userMetadata)->toBe(['0' => 'zero', 'name' => 'Ada'])
        ->and(Claims::fromArray([])->sub)->toBe('')
        ->and(Claims::fromArray(['aud' => 5])->aud)->toBeNull()
        ->and(Claims::fromArray(['phone' => '+34600000000', 'is_anonymous' => true])->phone)->toBe('+34600000000')
        ->and(Claims::fromArray(['is_anonymous' => true])->isAnonymous)->toBeTrue();
});

test('Claims::isExpired is exact at the boundary and false without an exp', function () {
    $claims = Claims::fromArray(['exp' => 1000]);

    expect($claims->isExpired(now: 999))->toBeFalse()
        ->and($claims->isExpired(now: 1000))->toBeTrue()
        ->and(Claims::fromArray([])->isExpired(now: PHP_INT_MAX))->toBeFalse()
        ->and(Claims::fromArray(['exp' => time() + 60])->isExpired())->toBeFalse()
        ->and(Claims::fromArray(['exp' => time() - 60])->isExpired())->toBeTrue();
});

// ---------------------------------------------------------------------------
// PKCE
// ---------------------------------------------------------------------------

test('Pkce::fromVerifier accepts exactly 43 and 128 characters and rejects 42 and 129', function () {
    expect(Pkce::fromVerifier(str_repeat('a', 43))->challenge)->not->toBe('')
        ->and(Pkce::fromVerifier(str_repeat('a', 128))->challenge)->not->toBe('')
        ->and(fn () => Pkce::fromVerifier(str_repeat('a', 42)))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => Pkce::fromVerifier(str_repeat('a', 129)))->toThrow(\InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// JWKS cache
// ---------------------------------------------------------------------------

test('the JWKS cache key is derived from the project URL, and a list with a non-object entry is refetched', function () {
    ['jwk' => $jwk, 'sign' => $sign] = JwtFixtures::es256();
    $jwt = JwtFixtures::jwt(['alg' => 'ES256', 'kid' => 'key-es'], JwtFixtures::payload(time() + 60), $sign);
    $cache = new ArrayCache();
    $expectedKey = 'supabase_jwks_' . sha1('https://demo.supabase.co');
    $cache->items[$expectedKey] = [$jwk, 'not-a-jwk'];

    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['keys' => [$jwk]])));

    $factory = new Psr17Factory();
    $client = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
        jwksCache: $cache,
    ));

    expect($client->auth()->getClaims($jwt)->sub)->toBe('user-1')
        ->and($http->requests)->toHaveCount(1)
        ->and($cache->sets)->toHaveCount(1)
        ->and($cache->sets[0][0])->toBe($expectedKey)
        ->and($cache->items[$expectedKey])->toBe([$jwk]);
});
