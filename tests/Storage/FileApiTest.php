<?php

declare(strict_types=1);

namespace Supabase\Tests\Storage;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function fileClient(MockClient $http): Client
{
    $f = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $f,
        streamFactory: $f,
    ));
}

test('upload posts the contents with content-type and returns the decoded result', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"Key":"avatars/a.png"}'));

    $result = fileClient($http)->storage()->from('avatars')->upload('a.png', 'BYTES', ['contentType' => 'image/png']);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/avatars/a.png')
        ->and($http->lastRequest->getHeaderLine('Content-Type'))->toBe('image/png')
        ->and((string) $http->lastRequest->getBody())->toBe('BYTES')
        ->and($result)->toBe(['Key' => 'avatars/a.png']);
});

test('upload sets x-upsert when requested and encodes nested paths per segment', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{}'));

    fileClient($http)->storage()->from('avatars')->upload('folder/my file.png', 'B', ['upsert' => true]);

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/avatars/folder/my%20file.png')
        ->and($http->lastRequest->getHeaderLine('x-upsert'))->toBe('true')
        ->and($http->lastRequest->getHeaderLine('Content-Type'))->toBe('application/octet-stream');
});

test('download returns the raw bytes', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'image/png'], 'PNGDATA'));

    $bytes = fileClient($http)->storage()->from('avatars')->download('a.png');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('GET')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/avatars/a.png')
        ->and($bytes)->toBe('PNGDATA');
});
