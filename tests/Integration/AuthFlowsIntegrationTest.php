<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Supabase\Exception\AuthException;

/**
 * The remaining GoTrue user flows against a real stack: profile update, sign
 * out (refresh token revoked), the OTP / magic-link login finished with
 * verifyOtp(token_hash), password recovery email, and the /authorize route.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('GoTrue: updateUser stores metadata and signOut revokes the refresh token', function (): void {
    $anon = IntegrationSupport::authClient();
    $session = IntegrationSupport::signUp($anon);

    $user = $anon->auth()->updateUser($session->accessToken, ['data' => ['name' => 'Ada']]);
    expect($user->id)->toBe($session->user->id)
        ->and($user->userMetadata['name'] ?? null)->toBe('Ada')
        ->and($anon->auth()->getUser($session->accessToken)->userMetadata['name'] ?? null)->toBe('Ada');

    $anon->auth()->signOut($session->accessToken);
    expect(fn () => $anon->auth()->refreshSession($session->refreshToken))->toThrow(AuthException::class);
});

test('GoTrue: signInWithOtp + verifyOtp(token_hash) logs in, resetPasswordForEmail sends a recovery link', function (): void {
    $mail = IntegrationSupport::mailUrl();
    if ($mail === null) {
        \PHPUnit\Framework\Assert::markTestSkipped('SUPABASE_MAIL_URL not set');
    }

    $anon = IntegrationSupport::authClient();
    $email = uniqid('otp_') . '@example.com';

    $anon->auth()->signInWithOtp(['email' => $email, 'create_user' => true]);
    $link = IntegrationSupport::waitForVerifyLink($mail, $email, type: 'magiclink');
    expect($link)->not->toBeNull();
    \assert(is_string($link));

    // The link carries the hashed token; verifyOtp() accepts it directly, no browser needed.
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    $tokenHash = $query['token'] ?? null;
    \assert(is_string($tokenHash));

    $session = $anon->auth()->verifyOtp(['token_hash' => $tokenHash, 'type' => 'magiclink']);
    expect($session->accessToken)->not->toBe('')
        ->and($session->user->email)->toBe($email);

    // GoTrue enforces a per-address cooldown between emails ([auth.email]
    // max_frequency), so recover a different, freshly signed-up account.
    $other = IntegrationSupport::signUp($anon)->user->email;
    \assert(is_string($other));
    $anon->auth()->resetPasswordForEmail($other, ['redirect_to' => 'http://localhost:3000/reset']);
    expect(IntegrationSupport::waitForVerifyLink($mail, $other, type: 'recovery'))->not->toBeNull();
});

test('GoTrue: getOAuthSignInUrl targets the real /authorize route', function (): void {
    $url = IntegrationSupport::authClient()->auth()->getOAuthSignInUrl('github', ['redirect_to' => 'http://localhost:3000/cb']);
    $response = (new GuzzleClient(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 10.0]))->get($url);

    // With no provider enabled locally GoTrue answers 400 "provider is not
    // enabled"; a redirect means one is. Either way the route resolved.
    expect($response->getStatusCode())->toBeIn([302, 303, 400]);
    if ($response->getStatusCode() === 400) {
        expect((string) $response->getBody())->toContain('provider');
    }
});
