<?php

declare(strict_types=1);

namespace Supabase\Tests\Exception;

use Nyholm\Psr7\Response;
use Supabase\Exception\FunctionsException;
use Supabase\Exception\SupabaseException;

test('stores status, code, and body', function () {
    $e = new SupabaseException('boom', 418, 'teapot', '{"x":1}');

    expect($e->getMessage())->toBe('boom')
        ->and($e->getStatusCode())->toBe(418)
        ->and($e->getErrorCode())->toBe('teapot')
        ->and($e->getResponseBody())->toBe('{"x":1}');
});

test('fromResponse parses a JSON error body', function () {
    $response = new Response(400, [], '{"message":"bad filter","code":"PGRST100"}');

    $e = SupabaseException::fromResponse($response);

    expect($e->getStatusCode())->toBe(400)
        ->and($e->getMessage())->toBe('bad filter')
        ->and($e->getErrorCode())->toBe('PGRST100')
        ->and($e->getResponseBody())->toBe('{"message":"bad filter","code":"PGRST100"}');
});

test('fromResponse falls back to the reason phrase on non-JSON body', function () {
    $response = new Response(503, [], 'Service Unavailable');

    $e = SupabaseException::fromResponse($response);

    expect($e->getStatusCode())->toBe(503)
        ->and($e->getMessage())->toBe('Service Unavailable');
});

test('fromResponse on a subclass returns that subclass', function () {
    $response = new Response(404, [], '{"message":"not found"}');

    $e = FunctionsException::fromResponse($response);

    expect($e)->toBeInstanceOf(FunctionsException::class);
});
