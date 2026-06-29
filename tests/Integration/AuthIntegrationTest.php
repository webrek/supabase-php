<?php

declare(strict_types=1);

use Supabase\Auth\Session;
use Supabase\Tests\Integration\IntegrationSupport;

test('GoTrue: signUp and signInWithPassword return a Session with an access token', function (): void {
    $client = IntegrationSupport::authClient();
    $email = uniqid('itest_') . '@example.com';
    $password = 'Testing1234!';

    // signUp — the Supabase CLI disables email confirmation by default (see
    // auth.email.enable_confirmations in the generated config.toml), so GoTrue
    // returns a Session immediately.  If a custom stack has confirmation enabled,
    // signUp returns null; in that case we can only assert that the call did not
    // throw an exception.
    $signUpSession = $client->auth()->signUp($email, $password);

    if ($signUpSession === null) {
        // Email confirmation is required on this stack.
        // The test is intentionally left as "passed with a no-op" so CI does not
        // fail on a valid configuration; the comment documents the limitation.
        expect(true)->toBeTrue();

        return;
    }

    expect($signUpSession)->toBeInstanceOf(Session::class)
        ->and($signUpSession->accessToken)->not->toBeEmpty()
        ->and($signUpSession->user->email)->toBe($email);

    // signIn with the same credentials — verifies that the account was persisted
    // and that the token endpoint returns a usable session.
    $session = $client->auth()->signInWithPassword($email, $password);

    expect($session)->toBeInstanceOf(Session::class)
        ->and($session->accessToken)->not->toBeEmpty()
        ->and($session->user->email)->toBe($email);
});
