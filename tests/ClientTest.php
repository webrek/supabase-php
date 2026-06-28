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

test('throws InvalidArgumentException for non-https url on public host', function () {
    $factory = new Psr17Factory();

    expect(fn () => new Client('http://example.com', 'k', new ClientOptions(
        httpClient: new MockClient(),
        requestFactory: $factory,
        streamFactory: $factory,
    )))->toThrow(\InvalidArgumentException::class);
});

test('allows http for localhost', function () {
    $factory = new Psr17Factory();

    $sb = new Client('http://localhost:54321', 'k', new ClientOptions(
        httpClient: new MockClient(),
        requestFactory: $factory,
        streamFactory: $factory,
    ));

    expect($sb->getTransport())->toBeInstanceOf(Transport::class);
});

test('allows http for 127.0.0.1', function () {
    $factory = new Psr17Factory();

    $sb = new Client('http://127.0.0.1:54321', 'k', new ClientOptions(
        httpClient: new MockClient(),
        requestFactory: $factory,
        streamFactory: $factory,
    ));

    expect($sb->getTransport())->toBeInstanceOf(Transport::class);
});

test('throws InvalidArgumentException when url has no scheme', function () {
    $factory = new Psr17Factory();

    expect(fn () => new Client('example.com', 'k', new ClientOptions(
        httpClient: new MockClient(),
        requestFactory: $factory,
        streamFactory: $factory,
    )))->toThrow(\InvalidArgumentException::class);
});

test('throws InvalidArgumentException when https url has no host', function () {
    expect(fn () => new Client('https:evil.example', 'k'))
        ->toThrow(\InvalidArgumentException::class);
});

test('throws InvalidArgumentException when url contains userinfo', function () {
    expect(fn () => new Client('https://u:p@host', 'k'))
        ->toThrow(\InvalidArgumentException::class);
});

test('url-validation errors do not leak embedded credentials', function () {
    $caught = null;
    try {
        new Client('https://user:p4ssw0rd@host', 'k');
    } catch (\Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    assert($caught instanceof \Throwable);
    expect($caught->getMessage())->not->toContain('p4ssw0rd');
});

test('non-https url still throws InvalidArgumentException without echoing the url', function () {
    $caught = null;
    try {
        new Client('http://example.com/leaky-path', 'k');
    } catch (\Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(\InvalidArgumentException::class);
    assert($caught instanceof \Throwable);
    expect($caught->getMessage())->not->toContain('leaky-path');
});

test('the api key is redacted from exception stack traces', function () {
    $caught = null;
    try {
        new Client('http://evil.example', 'SECRETKEY123');
    } catch (\Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    assert($caught instanceof \Throwable);
    expect($caught->getTraceAsString())->not->toContain('SECRETKEY123');
});

test('Client must not be serialized', function () {
    $factory = new Psr17Factory();
    $sb = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: new MockClient(),
        requestFactory: $factory,
        streamFactory: $factory,
    ));

    expect(fn () => serialize($sb))->toThrow(\LogicException::class);
});

test('ClientOptions __debugInfo redacts the access token and sensitive headers', function () {
    $options = new ClientOptions(
        headers: ['X-Api-Key' => 'HEADERSECRET', 'X-Visible' => 'shown'],
        accessToken: 'JWT123',
    );

    ob_start();
    var_dump($options);
    $output = (string) ob_get_clean();

    expect($output)->not->toContain('JWT123')
        ->and($output)->not->toContain('HEADERSECRET')
        ->and($output)->toContain('***redacted***')
        ->and($output)->toContain('shown');
});

test('json_encode(ClientOptions) does not expose the raw accessToken', function () {
    $options = new ClientOptions(accessToken: 'my-secret-token');
    $json = json_encode($options);
    expect($json)->not->toContain('my-secret-token')
        ->and($json)->toContain('***redacted***');
});

test('ClientOptions must not be serialized', function () {
    expect(fn () => serialize(new ClientOptions(accessToken: 'x')))
        ->toThrow(\LogicException::class);
});

test('Client must not be unserialized', function () {
    $factory = new Psr17Factory();
    $sb = new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: new MockClient(),
        requestFactory: $factory,
        streamFactory: $factory,
    ));

    expect(fn () => $sb->__unserialize([]))->toThrow(\LogicException::class);
});

test('ClientOptions must not be unserialized', function () {
    expect(fn () => (new ClientOptions())->__unserialize([]))
        ->toThrow(\LogicException::class);
});
