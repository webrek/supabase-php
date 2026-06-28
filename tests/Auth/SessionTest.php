<?php

declare(strict_types=1);

namespace Supabase\Tests\Auth;

use Supabase\Auth\Session;

test('Session::fromArray maps tokens and builds the User', function () {
    $s = Session::fromArray([
        'access_token' => 'AT',
        'refresh_token' => 'RT',
        'expires_in' => 3600,
        'expires_at' => 1893456000,
        'token_type' => 'bearer',
        'user' => ['id' => 'uuid-1', 'email' => 'a@b.com'],
    ]);

    expect($s->accessToken)->toBe('AT')
        ->and($s->refreshToken)->toBe('RT')
        ->and($s->expiresIn)->toBe(3600)
        ->and($s->expiresAt)->toBe(1893456000)
        ->and($s->tokenType)->toBe('bearer')
        ->and($s->user->id)->toBe('uuid-1');
});

test('Session redacts tokens in var_dump output', function () {
    $s = Session::fromArray(['access_token' => 'SECRET_AT', 'refresh_token' => 'SECRET_RT', 'user' => ['id' => 'x']]);

    ob_start();
    var_dump($s);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain('SECRET_AT')
        ->and($dump)->not->toContain('SECRET_RT')
        ->and($dump)->toContain('***redacted***');
});

test('Session cannot be serialized (holds credentials)', function () {
    $s = Session::fromArray(['access_token' => 'AT', 'refresh_token' => 'RT', 'user' => ['id' => 'x']]);
    expect(fn () => serialize($s))->toThrow(\LogicException::class);
});

test('Session redacts tokens in json_encode output', function () {
    $s = Session::fromArray(['access_token' => 'SECRET_AT', 'refresh_token' => 'SECRET_RT', 'user' => ['id' => 'x']]);
    $json = (string) json_encode($s);

    expect($json)->not->toContain('SECRET_AT')
        ->and($json)->not->toContain('SECRET_RT')
        ->and($json)->toContain('***redacted***');
});
