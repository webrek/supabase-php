<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Supabase\Auth\Pkce;
use Supabase\Exception\AuthException;

/**
 * PKCE end to end without an external provider: GoTrue's magic link supports the
 * PKCE flow, so signInWithOtp() with a code challenge → the email in the local
 * mail catcher → the verify link answers with a redirect carrying ?code= →
 * exchangeCodeForSession() turns it into a Session with the verifier.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php);
 * the mail round-trip additionally needs SUPABASE_MAIL_URL (Mailpit).
 */
test('GoTrue: PKCE magic-link flow ends in exchangeCodeForSession() returning a Session', function (): void {
    $mail = IntegrationSupport::mailUrl();
    if ($mail === null) {
        \PHPUnit\Framework\Assert::markTestSkipped('SUPABASE_MAIL_URL not set');
    }

    $anon = IntegrationSupport::authClient();
    $email = uniqid('pkce_') . '@example.com';
    $pkce = Pkce::generate();

    $anon->auth()->signInWithOtp([
        'email' => $email,
        'create_user' => true,
        'code_challenge' => $pkce->challenge,
        'code_challenge_method' => Pkce::METHOD,
    ]);

    $link = IntegrationSupport::waitForVerifyLink($mail, $email);
    expect($link)->not->toBeNull();
    \assert(is_string($link));

    // Following the link is what the browser would do; GoTrue answers with a
    // redirect to redirect_to carrying the one-time ?code=.
    $response = (new GuzzleClient(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 10.0]))->get($link);
    $location = $response->getHeaderLine('Location');
    expect($response->getStatusCode())->toBeIn([302, 303])
        ->and($location)->toContain('code=');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $code = $query['code'] ?? null;
    \assert(is_string($code));

    $session = $anon->auth()->exchangeCodeForSession($code, $pkce->verifier);
    expect($session->accessToken)->not->toBe('')
        ->and($session->user->email)->toBe($email);

    // A verifier that does not match the challenge, or a replayed code, is refused.
    expect(fn () => $anon->auth()->exchangeCodeForSession($code, Pkce::generate()->verifier))
        ->toThrow(AuthException::class);
});

test('GoTrue: a bogus PKCE code and a bogus ID token are refused with AuthException', function (): void {
    $auth = IntegrationSupport::authClient()->auth();

    expect(fn () => $auth->exchangeCodeForSession('not-a-code', Pkce::generate()->verifier))
        ->toThrow(AuthException::class)
        ->and(fn () => $auth->signInWithIdToken('google', 'not-an-id-token'))
        ->toThrow(AuthException::class);
});
