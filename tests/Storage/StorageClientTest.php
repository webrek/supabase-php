<?php

declare(strict_types=1);

namespace Supabase\Tests\Storage;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Storage\Bucket;
use Supabase\Tests\Support\MockClient;

function storageClient(MockClient $http): Client
{
    $f = new Psr17Factory();

    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(
        httpClient: $http,
        requestFactory: $f,
        streamFactory: $f,
    ));
}

test('createBucket posts to /bucket and returns the bucket name', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"name":"avatars"}'));

    $name = storageClient($http)->storage()->createBucket('avatars', ['public' => true]);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket')
        ->and((string) $http->lastRequest->getBody())->toBe('{"id":"avatars","name":"avatars","public":true}')
        ->and($name)->toBe('avatars');
});

test('getBucket returns a Bucket', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"avatars","name":"avatars","public":true}'));

    $bucket = storageClient($http)->storage()->getBucket('avatars');

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('GET')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket/avatars')
        ->and($bucket)->toBeInstanceOf(Bucket::class)
        ->and($bucket->public)->toBeTrue();
});

test('listBuckets maps to Bucket objects', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"id":"a","name":"a"},{"id":"b","name":"b"}]'));

    $buckets = storageClient($http)->storage()->listBuckets();

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('GET')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket')
        ->and($buckets)->toHaveCount(2)
        ->and($buckets[0])->toBeInstanceOf(Bucket::class)
        ->and($buckets[1]->id)->toBe('b');
});

test('getBucket rawurlencodes the bucket id in the path (path-injection guard)', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"id":"my bucket","name":"my bucket","public":false}'));

    storageClient($http)->storage()->getBucket('my bucket');

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket/my%20bucket');
});

test('updateBucket PUTs, deleteBucket DELETEs, emptyBucket POSTs /empty', function () {
    $http = new MockClient();
    $http->queue(new Response(200, [], '{}'));
    storageClient($http)->storage()->updateBucket('avatars', ['public' => false]);
    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('PUT')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket/avatars')
        ->and((string) $http->lastRequest->getBody())->toBe('{"public":false}');

    $http2 = new MockClient();
    $http2->queue(new Response(200, [], '{}'));
    storageClient($http2)->storage()->deleteBucket('avatars');
    \assert($http2->lastRequest !== null);
    expect($http2->lastRequest->getMethod())->toBe('DELETE')
        ->and((string) $http2->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket/avatars');

    $http3 = new MockClient();
    $http3->queue(new Response(200, [], '{}'));
    storageClient($http3)->storage()->emptyBucket('avatars');
    \assert($http3->lastRequest !== null);
    expect($http3->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http3->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/bucket/avatars/empty');
});

test('storage() is memoized', function () {
    $c = storageClient(new MockClient());
    expect($c->storage())->toBe($c->storage());
});
