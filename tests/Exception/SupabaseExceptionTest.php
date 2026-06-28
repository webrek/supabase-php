<?php

declare(strict_types=1);

namespace Supabase\Tests\Exception;

use Nyholm\Psr7\Response;
use Supabase\Exception\FunctionsException;
use Supabase\Exception\PostgrestException;
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

test('fromResponse accepts a float error code', function () {
    $response = new Response(400, [], '{"message":"x","code":403.0}');

    $e = SupabaseException::fromResponse($response);

    expect($e->getErrorCode())->toBe('403')
        ->and($e->getMessage())->toBe('x');
});

test('fromResponse does not throw on a malformed JSON body', function () {
    $response = new Response(503, [], 'Service Unavailable');

    $e = SupabaseException::fromResponse($response);

    expect($e->getMessage())->toBe('Service Unavailable')
        ->and($e->getStatusCode())->toBe(503);
});

test('baseline redaction applies to PostgrestException (access_token now redacted)', function () {
    $body = '{"access_token":"supersecret","message":"x"}';
    $e = PostgrestException::fromResponse(new Response(400, [], $body));
    expect((string) $e->getResponseBody())->not->toContain('supersecret')
        ->and((string) $e->getResponseBody())->toContain('***redacted***');
});

test('baseline redaction covers all baseline keys across unspecialized subclass', function () {
    $body = '{"apikey":"k1","access_token":"at","refresh_token":"rt","token":"t","password":"p","secret":"s","message":"ok"}';
    $e = FunctionsException::fromResponse(new Response(400, [], $body));
    $stored = (string) $e->getResponseBody();
    expect($stored)->not->toContain('k1')
        ->and($stored)->not->toContain('"at"')
        ->and($stored)->not->toContain('"rt"')
        ->and($stored)->not->toContain('"t"')
        ->and($stored)->not->toContain('"p"')
        ->and($stored)->not->toContain('"s"')
        ->and($stored)->toContain('ok');
});
