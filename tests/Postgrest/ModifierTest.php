<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function modClient(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

test('order asc/desc and nullsFirst', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    modClient($http)->from('t')->select('*')->order('created_at', ascending: false)->execute();
    \assert($http->lastRequest !== null);
    $uri = (string) $http->lastRequest->getUri();
    expect(substr($uri, strpos($uri, '?') + 1))->toBe('select=%2A&order=created_at.desc.nullslast');
});

test('limit and range map to limit/offset', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    modClient($http)->from('t')->select('*')->range(10, 19)->execute();
    \assert($http->lastRequest !== null);
    $uri = (string) $http->lastRequest->getUri();
    expect(substr($uri, strpos($uri, '?') + 1))->toBe('select=%2A&offset=10&limit=10');
});

test('single sets the object accept header and returns one row', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":1}'));
    $row = modClient($http)->from('t')->select('*')->single()->execute();
    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getHeaderLine('Accept'))->toBe('application/vnd.pgrst.object+json')
        ->and($row)->toBe(['id' => 1]);
});

test('maybeSingle returns the first row or null', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"id":1}]'));
    expect(modClient($http)->from('t')->select('*')->maybeSingle()->execute())->toBe(['id' => 1]);

    $http2 = new MockClient();
    $http2->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    expect(modClient($http2)->from('t')->select('*')->maybeSingle()->execute())->toBeNull();
});

test('limit sets the limit param on its own', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    modClient($http)->from('t')->select('id')->limit(5)->execute();

    \assert($http->lastRequest !== null);
    $uri = (string) $http->lastRequest->getUri();
    expect(substr($uri, strpos($uri, '?') + 1))->toBe('select=id&limit=5');
});

test('filter stringifies null and booleans the PostgREST way', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    modClient($http)->from('t')->select('id')->filter('deleted_at', 'is', null)->filter('active', 'is', true)->filter('n', 'eq', 3)->execute();

    \assert($http->lastRequest !== null);
    $uri = (string) $http->lastRequest->getUri();
    expect(substr($uri, strpos($uri, '?') + 1))->toBe('select=id&deleted_at=is.null&active=is.true&n=eq.3');
});

test('execute returns null for an empty body, such as a 204 from a minimal update', function () {
    $http = new MockClient();
    $http->queue(new Response(204, [], ''));

    expect(modClient($http)->from('t')->update(['a' => 1])->eq('id', 1)->execute())->toBeNull();
});
