<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use Supabase\Auth\User;
use Supabase\Exception\AuthException;

/**
 * The GoTrue Admin API with the service-role key against a real stack: the
 * full user lifecycle plus invitations and generated links — the calls whose
 * wire shape only the server can confirm.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Admin API: create, fetch, update, list, invite, generate a link and delete users', function (): void {
    $admin = IntegrationSupport::client()->auth()->admin();
    $email = uniqid('admin_') . '@example.com';

    $user = $admin->createUser([
        'email' => $email,
        'password' => 'Testing1234!',
        'email_confirm' => true,
        'user_metadata' => ['plan' => 'free'],
    ]);
    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe($email)
        ->and($user->userMetadata['plan'] ?? null)->toBe('free');

    expect($admin->getUserById($user->id)->email)->toBe($email);

    $updated = $admin->updateUserById($user->id, ['user_metadata' => ['plan' => 'pro']]);
    expect($updated->id)->toBe($user->id)
        ->and($updated->userMetadata['plan'] ?? null)->toBe('pro');

    // Earlier runs leave users behind, so walk a few pages until ours shows up.
    $found = false;
    for ($page = 1; $page <= 10 && ! $found; $page++) {
        $batch = $admin->listUsers(page: $page, perPage: 100);
        if ($batch === []) {
            break;
        }
        foreach ($batch as $listed) {
            if ($listed->id === $user->id) {
                $found = true;
            }
        }
    }
    expect($found)->toBeTrue();

    $inviteEmail = uniqid('invite_') . '@example.com';
    $invited = $admin->inviteUserByEmail($inviteEmail, ['data' => ['via' => 'integration']]);
    expect($invited->email)->toBe($inviteEmail);
    $mail = IntegrationSupport::mailUrl();
    if ($mail !== null) {
        expect(IntegrationSupport::waitForVerifyLink($mail, $inviteEmail, type: 'invite'))->not->toBeNull();
    }

    $link = $admin->generateLink(['type' => 'magiclink', 'email' => $email]);
    $actionLink = $link['action_link'] ?? null;
    \assert(is_string($actionLink));
    expect($actionLink)->toContain('/auth/v1/verify')
        ->and($link['hashed_token'] ?? null)->toBeString()
        ->and($link['verification_type'] ?? null)->toBe('magiclink');

    $admin->deleteUser($user->id);
    $admin->deleteUser($invited->id);
    expect(fn () => $admin->getUserById($user->id))->toThrow(AuthException::class);
});
