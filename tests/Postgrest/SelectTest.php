<?php

declare(strict_types=1);

namespace Supabase\Tests\Postgrest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Exception\PostgrestException;
use Supabase\Tests\Support\MockClient;

function pgClient(MockClient $http): Client
{
    $factory = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

test('select issues a GET to the table with the select param and returns rows', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"id":1,"name":"a"}]'));

    $rows = pgClient($http)->from('users')->select('id,name')->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/rest/v1/users?select=id%2Cname')
        ->and($request->getHeaderLine('apikey'))->toBe('ANON')
        ->and($rows)->toBe([['id' => 1, 'name' => 'a']]);
});

test('select defaults columns to *', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    pgClient($http)->from('users')->select()->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect((string) $request->getUri())->toBe('https://demo.supabase.co/rest/v1/users?select=%2A');
});

test('a PostgREST error response throws PostgrestException', function () {
    $http = new MockClient();
    $http->queue(new Response(400, ['Content-Type' => 'application/json'], '{"message":"bad","code":"PGRST100"}'));

    expect(fn () => pgClient($http)->from('users')->select()->execute())
        ->toThrow(PostgrestException::class, 'bad');
});

test('table name is url-encoded in the path', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    pgClient($http)->from('weird table')->select('*')->execute();

    $request = $http->lastRequest;
    \assert($request !== null);
    expect((string) $request->getUri())->toStartWith('https://demo.supabase.co/rest/v1/weird%20table?');
});
