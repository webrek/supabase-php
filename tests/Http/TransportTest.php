<?php

declare(strict_types=1);

namespace Supabase\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Supabase\Exception\SupabaseException;
use Supabase\Http\Transport;
use Supabase\Tests\Support\MockClient;

function makeTransport(MockClient $client): Transport
{
    $factory = new Psr17Factory();

    return new Transport(
        'https://demo.supabase.co',
        ['apikey' => 'KEY', 'Authorization' => 'Bearer KEY'],
        $client,
        $factory,
        $factory,
    );
}

test('builds url, applies default headers, and appends query', function () {
    $client = new MockClient();
    $client->queue(new Response(200, [], '{"ok":true}'));

    $transport = makeTransport($client);
    $transport->request('GET', '/functions/v1/hi', ['query' => ['a' => '1', 'b' => '2']]);

    $request = $client->lastRequest;
    expect($request)->toBeInstanceOf(RequestInterface::class);
    assert($request instanceof RequestInterface);

    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://demo.supabase.co/functions/v1/hi?a=1&b=2')
        ->and($request->getHeaderLine('apikey'))->toBe('KEY')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer KEY');
});

test('json-encodes array bodies and sets content-type', function () {
    $client = new MockClient();
    $client->queue(new Response(200, [], '{}'));

    $transport = makeTransport($client);
    $transport->request('POST', '/functions/v1/hi', ['body' => ['name' => 'world']]);

    $request = $client->lastRequest;
    assert($request instanceof RequestInterface);

    expect($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and((string) $request->getBody())->toBe('{"name":"world"}');
});

test('per-request headers override defaults', function () {
    $client = new MockClient();
    $client->queue(new Response(200, [], '{}'));

    $transport = makeTransport($client);
    $transport->request('GET', '/x', ['headers' => ['apikey' => 'OTHER']]);

    $request = $client->lastRequest;
    assert($request instanceof RequestInterface);

    expect($request->getHeaderLine('apikey'))->toBe('OTHER');
});

test('wraps un-encodable array body in SupabaseException not JsonException', function () {
    $client = new MockClient();
    $factory = new Psr17Factory();
    $transport = new Transport('https://demo.supabase.co', [], $client, $factory, $factory);

    expect(fn () => $transport->request('POST', '/x', ['body' => ['x' => "\xB1\x31"]]))
        ->toThrow(SupabaseException::class);
});

test('wraps PSR-18 network errors in SupabaseException', function () {
    $failing = new class () implements ClientInterface {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class ('offline') extends \RuntimeException implements ClientExceptionInterface {};
        }
    };
    $factory = new Psr17Factory();
    $transport = new Transport('https://demo.supabase.co', [], $failing, $factory, $factory);

    expect(fn () => $transport->request('GET', '/x'))
        ->toThrow(SupabaseException::class, 'offline');
});
