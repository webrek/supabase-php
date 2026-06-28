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
