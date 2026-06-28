<?php

declare(strict_types=1);

namespace Supabase\Tests\Exception;

use Nyholm\Psr7\Response;
use Supabase\Exception\RealtimeException;

test('RealtimeException redacts apikey and token fields from the response body', function () {
    $body = '{"apikey":"secret-key","access_token":"jwt-abc","refresh_token":"r-123","token":"t-9","status":"error"}';
    $e = RealtimeException::fromResponse(new Response(400, [], $body));

    $stored = $e->getResponseBody();
    expect($stored)->not->toContain('secret-key')
        ->and($stored)->not->toContain('jwt-abc')
        ->and($stored)->not->toContain('r-123')
        ->and($stored)->not->toContain('t-9')
        ->and($stored)->toContain('"status":"error"')
        ->and($e)->toBeInstanceOf(RealtimeException::class);
});
