<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

/**
 * @param callable(\Supabase\Postgrest\FilterBuilder): \Supabase\Postgrest\FilterBuilder $apply
 */
function advQuery(callable $apply): string
{
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));
    $f = new Psr17Factory();
    $c = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
    $apply($c->from('t')->select('*'))->execute();
    $request = $http->lastRequest;
    \assert($request !== null);
    $uri = (string) $request->getUri();
    return substr($uri, strpos($uri, '?') + 1);
}

test('contains array serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->contains('tags', ['a', 'b'])))->toBe('select=%2A&tags=cs.%7B%22a%22%2C%22b%22%7D');
});

test('contains string serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->contains('range', '[1,5]')))->toBe('select=%2A&range=cs.%5B1%2C5%5D');
});

test('containedBy serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->containedBy('tags', ['a'])))->toBe('select=%2A&tags=cd.%7B%22a%22%7D');
});

test('rangeGt serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->rangeGt('r', '[1,5]')))->toBe('select=%2A&r=sr.%5B1%2C5%5D');
});

test('rangeGte serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->rangeGte('r', '[1,5]')))->toBe('select=%2A&r=nxl.%5B1%2C5%5D');
});

test('rangeLt serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->rangeLt('r', '[1,5]')))->toBe('select=%2A&r=sl.%5B1%2C5%5D');
});

test('rangeLte serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->rangeLte('r', '[1,5]')))->toBe('select=%2A&r=nxr.%5B1%2C5%5D');
});

test('rangeAdjacent serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->rangeAdjacent('r', '[1,5]')))->toBe('select=%2A&r=adj.%5B1%2C5%5D');
});

test('overlaps array serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->overlaps('t', ['a'])))->toBe('select=%2A&t=ov.%7B%22a%22%7D');
});

test('overlaps string serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->overlaps('t', '(1,5)')))->toBe('select=%2A&t=ov.%281%2C5%29');
});

test('containedBy string serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->containedBy('range', '[1,5]')))->toBe('select=%2A&range=cd.%5B1%2C5%5D');
});

test('textSearch fts serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->textSearch('body', 'cat')))->toBe('select=%2A&body=fts.cat');
});

test('textSearch phfts no config serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->textSearch('body', 'cat', null, 'phfts')))->toBe('select=%2A&body=phfts.cat');
});

test('textSearch wfts with config serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->textSearch('body', 'cat', 'english', 'wfts')))->toBe('select=%2A&body=wfts%28english%29.cat');
});

test('textSearch plfts with config serializes correctly', function () {
    expect(advQuery(fn ($b) => $b->textSearch('body', 'cat', 'english', 'plfts')))->toBe('select=%2A&body=plfts%28english%29.cat');
});
