<?php

declare(strict_types=1);

namespace Supabase\Tests\Http;

use Supabase\Http\HeaderRedaction;

test('redact tolerates non-string header keys and redacts apikey', function () {
    $result = HeaderRedaction::redact(['0' => 'v', 'apikey' => 'SECRET']);

    expect($result['apikey'])->toBe(HeaderRedaction::REDACTED)
        ->and($result[0])->toBe('v');
});

test('redact leaves benign X-Cache-Key un-redacted', function () {
    $result = HeaderRedaction::redact(['X-Cache-Key' => 'plain']);

    expect($result['X-Cache-Key'])->toBe('plain');
});

test('redact redacts the Authorization header', function () {
    $result = HeaderRedaction::redact(['Authorization' => 'Bearer x']);

    expect($result['Authorization'])->toBe(HeaderRedaction::REDACTED);
});

test('isSensitive matches every well-known credential header, case-insensitively', function () {
    foreach (['authorization', 'Authorization', 'APIKEY', 'apikey', 'cookie', 'Cookie', 'proxy-authorization', 'Proxy-Authorization', 'x-api-key', 'X-Api-Key'] as $name) {
        expect(HeaderRedaction::isSensitive($name))->toBeTrue("{$name} should be sensitive");
    }
});

test('isSensitive matches any header whose name contains auth, token, secret or cookie', function () {
    foreach (['X-Auth-Request', 'X-Refresh-Token', 'X-Client-Secret', 'Set-Cookie', 'x-oauth-scopes'] as $name) {
        expect(HeaderRedaction::isSensitive($name))->toBeTrue("{$name} should be sensitive");
    }
    foreach (['Content-Type', 'Accept', 'X-Request-Id', 'Prefer', 'Range'] as $name) {
        expect(HeaderRedaction::isSensitive($name))->toBeFalse("{$name} should not be sensitive");
    }
});
