<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Postgrest\FilterBuilder;
use Supabase\Tests\Support\MockClient;

function fb(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

/** Build a select+filter chain and return the request query string.
 * @param callable(FilterBuilder): FilterBuilder $apply
 */
function queryFor(callable $apply): string
{
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    $b = fb($http)->from('t')->select('*');
    $result = $apply($b);
    $result->execute();
    $request = $http->lastRequest;
    \assert($request !== null);
    $uri = (string) $request->getUri();
    return substr($uri, strpos($uri, '?') + 1);
}

test('eq serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->eq('id', 5));
    expect($result)->toBe('select=%2A&id=eq.5');
});

test('neq serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->neq('id', 5));
    expect($result)->toBe('select=%2A&id=neq.5');
});

test('gt serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->gt('age', 18));
    expect($result)->toBe('select=%2A&age=gt.18');
});

test('gte serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->gte('age', 18));
    expect($result)->toBe('select=%2A&age=gte.18');
});

test('lt serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->lt('age', 65));
    expect($result)->toBe('select=%2A&age=lt.65');
});

test('lte serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->lte('age', 65));
    expect($result)->toBe('select=%2A&age=lte.65');
});

test('like serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->like('name', '%jo%'));
    expect($result)->toBe('select=%2A&name=like.%25jo%25');
});

test('ilike serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->ilike('name', '%jo%'));
    expect($result)->toBe('select=%2A&name=ilike.%25jo%25');
});

test('is true serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->is('active', true));
    expect($result)->toBe('select=%2A&active=is.true');
});

test('is null serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->is('deleted', null));
    expect($result)->toBe('select=%2A&deleted=is.null');
});

test('in serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->in('id', [1, 2, 3]));
    expect($result)->toBe('select=%2A&id=in.%281%2C2%2C3%29');
});

test('in with strings serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->in('tag', ['a', 'b']));
    expect($result)->toBe('select=%2A&tag=in.%28%22a%22%2C%22b%22%29');
});

test('not serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->not('id', 'eq', 5));
    expect($result)->toBe('select=%2A&id=not.eq.5');
});

test('or serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->or('age.gte.18,age.lte.65'));
    expect($result)->toBe('select=%2A&or=%28age.gte.18%2Cage.lte.65%29');
});

test('filter serializes to PostgREST query param', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->filter('id', 'eq', 5));
    expect($result)->toBe('select=%2A&id=eq.5');
});

test('match adds one eq per pair', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->match(['a' => 1, 'b' => 'x']));
    expect($result)->toBe('select=%2A&a=eq.1&b=eq.x');
});

test('eq with a boolean serializes true/false', function () {
    $result = queryFor(fn (FilterBuilder $b) => $b->eq('active', false));
    expect($result)->toBe('select=%2A&active=eq.false');
});
