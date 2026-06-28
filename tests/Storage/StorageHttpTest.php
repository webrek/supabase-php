<?php

declare(strict_types=1);

namespace Supabase\Tests\Storage;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Exception\StorageException;
use Supabase\Http\Transport;
use Supabase\Storage\StorageHttp;
use Supabase\Tests\Support\MockClient;

function storageHttp(MockClient $client): StorageHttp
{
    $f = new Psr17Factory();
    $transport = new Transport('https://demo.supabase.co', ['apikey' => 'ANON', 'Authorization' => 'Bearer ANON'], $client, $f, $f);

    return new StorageHttp($transport);
}

test('requestJson prepends /storage/v1, sends body, returns decoded array', function () {
    $client = new MockClient();
    $client->queue(new Response(200, ['Content-Type' => 'application/json'], '{"name":"avatars"}'));

    $data = storageHttp($client)->requestJson('POST', '/bucket', ['body' => ['id' => 'avatars']]);

    \assert($client->lastRequest !== null);
    expect((string) $client->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket')
        ->and((string) $client->lastRequest->getBody())->toBe('{"id":"avatars"}')
        ->and($data)->toBe(['name' => 'avatars']);
});

test('requestJson throws StorageException on non-2xx', function () {
    $client = new MockClient();
    $client->queue(new Response(404, ['Content-Type' => 'application/json'], '{"error":"not_found","message":"Bucket not found"}'));

    expect(fn () => storageHttp($client)->requestJson('GET', '/bucket/missing'))
        ->toThrow(StorageException::class);
});

test('requestJson returns [] for an empty body', function () {
    $client = new MockClient();
    $client->queue(new Response(200, [], ''));
    expect(storageHttp($client)->requestJson('DELETE', '/bucket/x'))->toBe([]);
});

test('requestRaw returns the raw body bytes', function () {
    $client = new MockClient();
    $client->queue(new Response(200, ['Content-Type' => 'image/png'], 'RAWBYTES'));

    $bytes = storageHttp($client)->requestRaw('GET', '/object/b/a.png');

    \assert($client->lastRequest !== null);
    expect((string) $client->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/b/a.png')
        ->and($bytes)->toBe('RAWBYTES');
});

test('requestRaw throws StorageException on a non-2xx response', function () {
    $client = new MockClient();
    $client->queue(new Response(404, ['Content-Type' => 'application/json'], '{"error":"not_found"}'));

    expect(fn () => storageHttp($client)->requestRaw('GET', '/object/b/missing.png'))
        ->toThrow(\Supabase\Exception\StorageException::class);
});

test('requestRaw enforces the maxBytes cap', function () {
    $client = new MockClient();
    $client->queue(new Response(200, [], 'abcdefghij')); // 10 bytes

    expect(fn () => storageHttp($client)->requestRaw('GET', '/object/b/big', [], 5))
        ->toThrow(\Supabase\Exception\SupabaseException::class);
});
