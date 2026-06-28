<?php

declare(strict_types=1);

namespace Supabase\Tests\Storage;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Tests\Support\MockClient;

function signClient(MockClient $http): Client
{
    $f = new Psr17Factory();
    return new Client('https://demo.supabase.co', 'ANON', new ClientOptions(httpClient: $http, requestFactory: $f, streamFactory: $f));
}

test('createSignedUrl returns an absolute URL from the relative signedURL', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"signedURL":"/object/sign/avatars/a.png?token=XYZ"}'));

    $url = signClient($http)->storage()->from('avatars')->createSignedUrl('a.png', 60);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('POST')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/sign/avatars/a.png')
        ->and((string) $http->lastRequest->getBody())->toBe('{"expiresIn":60}')
        ->and($url)->toBe('https://demo.supabase.co/storage/v1/object/sign/avatars/a.png?token=XYZ');
});

test('createSignedUrls posts the paths and returns the array', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '[{"path":"a.png","signedURL":"/object/sign/avatars/a.png?token=A"}]'));

    $res = signClient($http)->storage()->from('avatars')->createSignedUrls(['a.png'], 60);

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/sign/avatars')
        ->and((string) $http->lastRequest->getBody())->toBe('{"expiresIn":60,"paths":["a.png"]}')
        ->and($res)->toHaveCount(1);
});

test('createSignedUploadUrl posts to the upload-sign endpoint', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"url":"/object/upload/sign/avatars/a.png?token=T","token":"T"}'));

    $res = signClient($http)->storage()->from('avatars')->createSignedUploadUrl('a.png');

    \assert($http->lastRequest !== null);
    expect((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/upload/sign/avatars/a.png')
        ->and($res['token'])->toBe('T');
});

test('uploadToSignedUrl PUTs the contents with the token query', function () {
    $http = new MockClient();
    $http->queue(new Response(200, ['Content-Type' => 'application/json'], '{"Key":"avatars/a.png"}'));

    signClient($http)->storage()->from('avatars')->uploadToSignedUrl('a.png', 'T', 'BYTES', ['contentType' => 'image/png']);

    \assert($http->lastRequest !== null);
    expect($http->lastRequest->getMethod())->toBe('PUT')
        ->and((string) $http->lastRequest->getUri())->toBe('https://demo.supabase.co/storage/v1/object/upload/sign/avatars/a.png?token=T')
        ->and($http->lastRequest->getHeaderLine('Content-Type'))->toBe('image/png')
        ->and((string) $http->lastRequest->getBody())->toBe('BYTES');
});

test('getPublicUrl builds the public URL without an HTTP call', function () {
    $http = new MockClient(); // nothing queued
    $url = signClient($http)->storage()->from('avatars')->getPublicUrl('folder/a.png');

    expect($url)->toBe('https://demo.supabase.co/storage/v1/object/public/avatars/folder/a.png')
        ->and($http->lastRequest)->toBeNull();
});
