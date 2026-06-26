<?php

declare(strict_types=1);

namespace Supabase\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Http\Transport;
use Supabase\Tests\Support\MockClient;

test('builds a transport with apikey and bearer auth headers', function () {
    $client = new MockClient();
    $client->queue(new Response(200, [], '{}'));
    $factory = new Psr17Factory();

    $sb = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));

    $sb->getTransport()->request('GET', '/x');

    $request = $client->lastRequest;
    expect($request)->not->toBeNull();
    if ($request !== null) {
        expect($request->getHeaderLine('apikey'))->toBe('ANON')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer ANON');
    }
});

test('accessToken overrides the bearer token but keeps the apikey', function () {
    $client = new MockClient();
    $client->queue(new Response(200, [], '{}'));
    $factory = new Psr17Factory();

    $sb = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
        accessToken: 'USER_JWT',
    ));

    $sb->getTransport()->request('GET', '/x');

    $request = $client->lastRequest;
    expect($request)->not->toBeNull();
    if ($request !== null) {
        expect($request->getHeaderLine('apikey'))->toBe('ANON')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer USER_JWT');
    }
});

test('getTransport returns a Transport instance', function () {
    $factory = new Psr17Factory();
    $sb = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: new MockClient(),
        requestFactory: $factory,
        streamFactory: $factory,
    ));

    expect($sb->getTransport())->toBeInstanceOf(Transport::class);
});
