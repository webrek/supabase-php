<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Auth\Jwks;
use Supabase\Auth\JwtVerifier;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\AuthException;
use Supabase\Tests\Support\JwtFixtures;
use Supabase\Tests\Support\MockClient;

/*
 * Security regression suite for getClaims(): the classic attacks on JWT
 * verifiers, each built against a freshly generated key set. A case that
 * starts being accepted is a vulnerability, whatever the mutation score says.
 */

beforeEach(fn () => Jwks::clearProcessCache());

/**
 * @param 'accept'|'reject' $expect
 * @return array{jwt: string, secret: ?string, jwks: ?string, status: int, expect: 'accept'|'reject'}
 */
function attack(string $jwt, ?string $jwks, string $expect = 'reject', ?string $secret = null, int $status = 200): array
{
    return ['jwt' => $jwt, 'secret' => $secret, 'jwks' => $jwks, 'status' => $status, 'expect' => $expect];
}

/**
 * Every vector, built against freshly generated keys (the dataset is lazy).
 *
 * @return array<string, array{0: array{jwt: string, secret: ?string, jwks: ?string, status: int, expect: 'accept'|'reject'}}>
 */
function jwtAttackVectors(): array
{
    ['jwk' => $jwk, 'sign' => $sign, 'publicPem' => $publicPem] = JwtFixtures::es256('key-es');
    ['jwk' => $evilJwk, 'sign' => $evilSign] = JwtFixtures::es256('key-es');   // same kid, attacker's key
    ['sign' => $rsSign] = JwtFixtures::rs256('key-es');
    $payload = JwtFixtures::payload(time() + 3600);
    $jwks = (string) json_encode(['keys' => [$jwk]]);
    $header = ['alg' => 'ES256', 'kid' => 'key-es'];
    $legit = JwtFixtures::jwt($header, $payload, $sign);
    [$h, $p, $s] = explode('.', $legit);
    $rawSig = JwtVerifier::base64UrlDecode($s);
    $flipped = $rawSig;
    $flipped[10] = chr(ord($flipped[10]) ^ 0x01);
    $noExp = $payload;
    unset($noExp['exp']);

    $cases = [
        'control: a legitimate ES256 token is accepted' => attack($legit, $jwks, 'accept'),
        'alg=none' => attack(JwtFixtures::jwt(['alg' => 'none', 'kid' => 'key-es'], $payload, fn () => 'x'), $jwks),
        'alg=none with an empty signature segment' => attack("$h.$p.", $jwks),
        'alg confusion: HS256 signed with the public key, jwtSecret configured' => attack(
            JwtFixtures::jwt(['alg' => 'HS256', 'kid' => 'key-es'], $payload, JwtFixtures::hs256($publicPem)),
            $jwks,
            secret: 'the-real-shared-secret-of-the-project',
        ),
        'alg confusion: HS256 signed with the public key, no jwtSecret (server says 401)' => attack(
            JwtFixtures::jwt(['alg' => 'HS256', 'kid' => 'key-es'], $payload, JwtFixtures::hs256($publicPem)),
            '{"msg":"invalid JWT"}',
            status: 401,
        ),
        'embedded jwk header pointing at the attacker key' => attack(JwtFixtures::jwt($header + ['jwk' => $evilJwk], $payload, $evilSign), $jwks),
        'jku / x5u headers pointing elsewhere' => attack(JwtFixtures::jwt($header + ['jku' => 'https://evil.test/jwks', 'x5u' => 'https://evil.test/c'], $payload, $evilSign), $jwks),
        'RS256 token against the EC key with the same kid' => attack(JwtFixtures::jwt(['alg' => 'RS256', 'kid' => 'key-es'], $payload, $rsSign), $jwks),
        'five-segment JWE-shaped token' => attack('a.b.c.d.e', $jwks),
        'segments that are not base64url' => attack('!!.@@.##', $jwks),
        'header that is a JSON array, not an object' => attack(JwtFixtures::b64url('[1]') . ".$p.$s", $jwks),
        'missing exp' => attack(JwtFixtures::jwt($header, $noExp, $sign), $jwks),
        'exp that is not a number' => attack(JwtFixtures::jwt($header, ['exp' => 'never'] + $payload, $sign), $jwks),
        'exp exactly now' => attack(JwtFixtures::jwt($header, ['exp' => time()] + $payload, $sign), $jwks),
        'one bit flipped in the signature' => attack("$h.$p." . JwtFixtures::b64url($flipped), $jwks),
        'signature truncated to 63 bytes' => attack("$h.$p." . JwtFixtures::b64url(substr($rawSig, 0, 63)), $jwks),
        'JWKS key on P-384 under the same kid' => attack($legit, (string) json_encode(['keys' => [['crv' => 'P-384'] + $jwk]])),
    ];

    $vectors = [];
    foreach ($cases as $name => $case) {
        $vectors[$name] = [$case];
    }

    return $vectors;
}

test('getClaims() holds against', function (array $case): void {
    $jwt = $case['jwt'] ?? null;
    $jwks = $case['jwks'] ?? null;
    $status = $case['status'] ?? null;
    $secret = $case['secret'] ?? null;
    $expect = $case['expect'] ?? null;
    \assert(is_string($jwt) && ($jwks === null || is_string($jwks)) && is_int($status) && ($secret === null || is_string($secret)) && is_string($expect));

    $http = new MockClient();
    if ($jwks !== null) {
        $http->queue(new Response($status, ['Content-Type' => 'application/json'], $jwks));
        $http->queue(new Response($status, ['Content-Type' => 'application/json'], $jwks)); // a refetch on unknown kid
    }
    $factory = new Psr17Factory();
    $client = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
        jwtSecret: $secret,
    ));

    if ($expect === 'accept') {
        expect($client->auth()->getClaims($jwt)->sub)->toBe('user-1');

        return;
    }

    expect(fn () => $client->auth()->getClaims($jwt))->toThrow(AuthException::class);
})->with(static fn (): array => jwtAttackVectors());
