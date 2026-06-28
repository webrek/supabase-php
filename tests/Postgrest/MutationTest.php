<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function mutClient(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

test('insert posts the body and returns null by default (return=minimal)', function () {
    $http = new MockClient();
    $http->queue(new Response(201, [], ''));
    $res = mutClient($http)->from('users')->insert(['email' => 'a@b.com'])->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getBody())->toBe('{"email":"a@b.com"}')
        ->and($request->getHeaderLine('Prefer'))->toContain('return=minimal')
        ->and($res)->toBeNull();
});

test('insert with select returns representation rows', function () {
    $http = new MockClient();
    $http->queue(new Response(201, ['Content-Type' => 'application/json'], '[{"id":1,"email":"a@b.com"}]'));
    $rows = mutClient($http)->from('users')->insert(['email' => 'a@b.com'])->select('id,email')->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getHeaderLine('Prefer'))->toContain('return=representation')
        ->and($rows)->toBe([['id' => 1, 'email' => 'a@b.com']]);
});

test('update patches with filters', function () {
    $http = new MockClient();
    $http->queue(new Response(204, [], ''));
    mutClient($http)->from('users')->update(['active' => false])->eq('id', 5)->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('PATCH')
        ->and((string) $request->getBody())->toBe('{"active":false}')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/rest/v1/users?id=eq.5');
});

test('delete issues DELETE with filters', function () {
    $http = new MockClient();
    $http->queue(new Response(204, [], ''));
    mutClient($http)->from('users')->delete()->eq('id', 5)->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('DELETE')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/rest/v1/users?id=eq.5');
});

test('upsert sets merge-duplicates and on_conflict', function () {
    $http = new MockClient();
    $http->queue(new Response(201, [], ''));
    mutClient($http)->from('users')->upsert(['id' => 1, 'email' => 'a@b.com'], 'id')->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('POST')
        ->and($request->getHeaderLine('Prefer'))->toContain('resolution=merge-duplicates')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/rest/v1/users?on_conflict=id');
});
