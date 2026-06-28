<?php

declare(strict_types=1);

namespace Supabase\Tests\Exception;

use Nyholm\Psr7\Response;
use Supabase\Exception\AuthException;
use Supabase\Exception\PostgrestException;

test('AuthException redacts token keys in the stored response body', function () {
    $body = '{"access_token":"AT","refresh_token":"RT","provider_token":"PT","id_token":"IT","error":"bad"}';
    $e = AuthException::fromResponse(new Response(400, [], $body));

    $stored = (string) $e->getResponseBody();
    expect($stored)->not->toContain('"AT"')
        ->and($stored)->not->toContain('"RT"')
        ->and($stored)->not->toContain('"PT"')
        ->and($stored)->not->toContain('"IT"')
        ->and(substr_count($stored, '***redacted***'))->toBe(4)
        ->and($stored)->toContain('bad'); // non-token fields preserved
});

test('PostgrestException does NOT redact (base behavior unchanged)', function () {
    $body = '{"access_token":"AT","message":"x"}';
    $e = PostgrestException::fromResponse(new Response(400, [], $body));
    expect((string) $e->getResponseBody())->toContain('AT');
});
