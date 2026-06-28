<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Supabase\Auth\User;

test('User::fromArray maps GoTrue fields', function () {
    $user = User::fromArray([
        'id' => 'uuid-1',
        'email' => 'a@b.com',
        'phone' => '',
        'role' => 'authenticated',
        'app_metadata' => ['provider' => 'email'],
        'user_metadata' => ['name' => 'Ada'],
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-03T00:00:00Z',
        'last_sign_in_at' => '2026-01-02T00:00:00Z',
    ]);

    expect($user->id)->toBe('uuid-1')
        ->and($user->email)->toBe('a@b.com')
        ->and($user->phone)->toBeNull() // GoTrue sends "" when unset → normalised to null
        ->and($user->role)->toBe('authenticated')
        ->and($user->appMetadata)->toBe(['provider' => 'email'])
        ->and($user->userMetadata)->toBe(['name' => 'Ada'])
        ->and($user->createdAt)->toBe('2026-01-01T00:00:00Z')
        ->and($user->updatedAt)->toBe('2026-01-03T00:00:00Z')
        ->and($user->lastSignInAt)->toBe('2026-01-02T00:00:00Z');
});

test('User::fromArray tolerates a minimal payload', function () {
    $user = User::fromArray(['id' => 'x']);
    expect($user->id)->toBe('x')
        ->and($user->email)->toBeNull()
        ->and($user->appMetadata)->toBe([]);
});
