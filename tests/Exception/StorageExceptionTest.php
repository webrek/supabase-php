<?php

declare(strict_types=1);

namespace Supabase\Tests\Exception;

use Nyholm\Psr7\Response;
use Supabase\Exception\PostgrestException;
use Supabase\Exception\StorageException;

test('StorageException redacts signed-url token fields', function () {
    $body = '{"signedURL":"/object/sign/b/a?token=AAA","token":"TTT","key":"KKK","message":"bad"}';
    $e = StorageException::fromResponse(new Response(400, [], $body));
    $stored = (string) $e->getResponseBody();

    expect($stored)->not->toContain('AAA')
        ->and($stored)->not->toContain('TTT')
        ->and($stored)->not->toContain('KKK')
        ->and(substr_count($stored, '***redacted***'))->toBe(3)
        ->and($stored)->toContain('bad');
});

test('PostgrestException still does NOT redact', function () {
    $e = PostgrestException::fromResponse(new Response(400, [], '{"token":"AAA","message":"x"}'));
    expect((string) $e->getResponseBody())->toContain('AAA');
});
