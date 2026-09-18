<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use Supabase\Auth\JwtVerifier;
use Supabase\Exception\AuthException;
use Supabase\Tests\Support\JwtFixtures;

/**
 * getClaims() against a real GoTrue: the token it issues must verify — through
 * the project JWKS for asymmetric keys, or through the server / the shared
 * secret for legacy HS256 — and a tampered copy must be rejected.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('getClaims() verifies a real GoTrue access token and rejects a tampered one', function (): void {
    $anon = IntegrationSupport::authClient();
    $session = IntegrationSupport::signUp($anon);

    $alg = JwtVerifier::decode($session->accessToken)['header']['alg'] ?? null;
    expect(in_array($alg, ['HS256', 'ES256', 'RS256'], true))->toBeTrue();

    // The key-set endpoint the JWKS path relies on exists on this stack.
    $jwks = $anon->getTransport()->request('GET', '/auth/v1/.well-known/jwks.json');
    expect($jwks->getStatusCode())->toBe(200);

    // No secret configured: JWKS for ES256 / RS256, one /auth/v1/user round-trip for HS256.
    $claims = $anon->auth()->getClaims($session->accessToken);
    expect($claims->sub)->toBe($session->user->id)
        ->and($claims->email)->toBe($session->user->email)
        ->and($claims->role)->toBe('authenticated')
        ->and($claims->aud)->toBe('authenticated')
        ->and($claims->isExpired())->toBeFalse();

    // Legacy HS256 verified locally with the stack's secret, when we know it.
    $secret = IntegrationSupport::jwtSecret();
    if ($alg === 'HS256' && $secret !== null) {
        $local = IntegrationSupport::authClient($secret)->auth()->getClaims($session->accessToken);
        expect($local->sub)->toBe($session->user->id);

        expect(fn () => IntegrationSupport::authClient(str_repeat('x', 40))->auth()->getClaims($session->accessToken))
            ->toThrow(AuthException::class, 'signature');
    }

    // Escalating the role in the payload invalidates the signature on every path.
    [$h, $p, $s] = explode('.', $session->accessToken);
    $payload = json_decode(JwtVerifier::base64UrlDecode($p), true);
    \assert(is_array($payload));
    $payload['role'] = 'service_role';
    $forged = $h . '.' . JwtFixtures::b64url((string) json_encode($payload)) . '.' . $s;

    expect(fn () => $anon->auth()->getClaims($forged))->toThrow(AuthException::class);
});
