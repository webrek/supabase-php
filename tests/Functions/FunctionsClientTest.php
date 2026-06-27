<?php

declare(strict_types=1);

namespace Supabase\Tests\Functions;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\FunctionsException;
use Supabase\Tests\Support\MockClient;

function functionsClient(MockClient $http): Client
{
    $factory = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

test('invoke posts to the function path with a json body', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"greeting":"hi world"}'));

    $result = functionsClient($http)->functions()->invoke('hello', ['body' => ['name' => 'world']]);

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/functions/v1/hello')
        ->and((string) $request->getBody())->toBe('{"name":"world"}')
        ->and($result)->toBe(['greeting' => 'hi world']);
});

test('invoke returns the raw body for non-json responses', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'text/plain'], 'pong'));

    $result = functionsClient($http)->functions()->invoke('ping');

    expect($result)->toBe('pong');
});

test('invoke throws FunctionsException on error status', function () {
    $http = new MockClient();
    $http->queue(new Response(500, ['Content-Type' => 'application/json'], '{"message":"boom"}'));

    expect(fn () => functionsClient($http)->functions()->invoke('broken'))
        ->toThrow(FunctionsException::class, 'boom');
});

test('functions() is memoised', function () {
    $client = functionsClient(new MockClient());

    expect($client->functions())->toBe($client->functions());
});

test('invoke returns null for an empty application/json body', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], ''));

    $result = functionsClient($http)->functions()->invoke('noop');

    expect($result)->toBeNull();
});

test('invoke throws FunctionsException for malformed json body', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{not json'));

    expect(fn () => functionsClient($http)->functions()->invoke('broken-body'))
        ->toThrow(FunctionsException::class);
});

test('invoke percent-encodes traversal segments in the function name', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{}'));

    functionsClient($http)->functions()->invoke('../auth/v1/admin');

    $request = $http->lastRequest;
    \assert($request !== null);
    $uri = (string) $request->getUri();

    expect($uri)->toContain('functions/v1/..%2Fauth%2Fv1%2Fadmin')
        ->and($uri)->not->toContain('functions/v1/../auth');
});

test('invoke throws InvalidArgumentException for an empty function name', function () {
    $http = new MockClient();

    expect(fn () => functionsClient($http)->functions()->invoke(''))
        ->toThrow(\InvalidArgumentException::class);

    expect($http->lastRequest)->toBeNull();
});

test('invoke throws InvalidArgumentException for a whitespace-only function name', function () {
    $http = new MockClient();

    expect(fn () => functionsClient($http)->functions()->invoke('   '))
        ->toThrow(\InvalidArgumentException::class);

    expect($http->lastRequest)->toBeNull();
});

test('invoke throws FunctionsException on a 3xx redirect response', function () {
    $http = new MockClient();
    $http->queue(new Response(302, ['Location' => 'https://evil.example/'], 'ignored body'));

    expect(fn () => functionsClient($http)->functions()->invoke('redir'))
        ->toThrow(FunctionsException::class);
});

test('invoke decodes a case-insensitive Application/JSON content type', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'Application/JSON'], '{"a":1}'));

    expect(functionsClient($http)->functions()->invoke('ci'))->toBe(['a' => 1]);
});

test('invoke decodes application/json with charset parameter', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"a":1}'));

    expect(functionsClient($http)->functions()->invoke('charset'))->toBe(['a' => 1]);
});

test('invoke decodes a +json suffixed media type', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/vnd.api+json'], '{"a":1}'));

    expect(functionsClient($http)->functions()->invoke('vnd'))->toBe(['a' => 1]);
});

test('invoke returns raw string when first media type is not json', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'text/html, application/json'], '<p>hi</p>'));

    expect(functionsClient($http)->functions()->invoke('html'))->toBe('<p>hi</p>');
});
