<?php

declare(strict_types=1);

namespace Supabase\Tests\Storage;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function listClient(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

test('list posts the prefix to the list endpoint and returns the array', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"name":"a.png"},{"name":"b.png"}]'));

    $items = listClient($http)->storage()->from('avatars')->list('folder');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/list/avatars')
        ->and((string) $http->lastRequest->getBody())->toBe('{"prefix":"folder"}')
        ->and($items)->toHaveCount(2);
});

test('remove DELETEs with the prefixes body', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"name":"a.png"}]'));

    $removed = listClient($http)->storage()->from('avatars')->remove(['a.png', 'b.png']);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('DELETE')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/avatars')
        ->and((string) $http->lastRequest->getBody())->toBe('{"prefixes":["a.png","b.png"]}')
        ->and($removed)->toHaveCount(1);
});

test('move posts source/destination and returns void', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{"message":"ok"}'));

    listClient($http)->storage()->from('avatars')->move('a.png', 'b.png');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/move')
        ->and((string) $http->lastRequest->getBody())->toBe('{"bucketId":"avatars","sourceKey":"a.png","destinationKey":"b.png"}');
});

test('copy posts source/destination to the copy endpoint', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{"Key":"avatars/b.png"}'));

    listClient($http)->storage()->from('avatars')->copy('a.png', 'b.png');

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/copy')
        ->and((string) $http->lastRequest->getBody())->toBe('{"bucketId":"avatars","sourceKey":"a.png","destinationKey":"b.png"}');
});
