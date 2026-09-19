<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;

/**
 * The Storage calls the basic round-trip leaves out, against a real stack:
 * bucket metadata, copy / move, signed download URLs (fetched for real),
 * signed upload URLs, public URLs after making the bucket public, and
 * emptying the bucket.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Storage: bucket metadata, copy/move, signed and public URLs, signed upload, empty bucket', function (): void {
    $storage = IntegrationSupport::client()->storage();
    $http = new GuzzleClient(['http_errors' => false, 'timeout' => 10.0]);
    $bucketId = 'objs-' . uniqid();

    $storage->createBucket($bucketId, ['public' => false]);
    $bucket = $storage->getBucket($bucketId);
    expect($bucket->id)->toBe($bucketId)
        ->and($bucket->public)->toBeFalse();

    $files = $storage->from($bucketId);
    $files->upload('a/one.txt', 'one', ['contentType' => 'text/plain']);
    $files->copy('a/one.txt', 'a/two.txt');
    $files->move('a/two.txt', 'b/three.txt');

    $names = static fn (array $listing): array => array_values(array_map(
        static fn (mixed $f): string => is_array($f) && is_string($f['name'] ?? null) ? $f['name'] : '',
        $listing,
    ));
    expect($names($files->list('a')))->toBe(['one.txt'])
        ->and($names($files->list('b')))->toBe(['three.txt'])
        ->and($files->download('b/three.txt'))->toBe('one');

    // A signed URL must actually serve the object without any credentials.
    $signed = $files->createSignedUrl('a/one.txt', 60);
    $fetched = $http->get($signed);
    expect($fetched->getStatusCode())->toBe(200)
        ->and((string) $fetched->getBody())->toBe('one');

    $many = $files->createSignedUrls(['a/one.txt', 'b/three.txt'], 60);
    expect($many)->toHaveCount(2);
    foreach ($many as $entry) {
        expect(is_array($entry) && is_string($entry['signedURL'] ?? null))->toBeTrue();
    }

    // Signed upload: the server hands out a token, the client uploads with it.
    $grant = $files->createSignedUploadUrl('c/four.txt');
    $token = $grant['token'] ?? null;
    if (! is_string($token)) {
        $grantUrl = $grant['url'] ?? null;
        \assert(is_string($grantUrl));
        parse_str((string) parse_url($grantUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
    }
    \assert(is_string($token));
    $files->uploadToSignedUrl('c/four.txt', $token, 'four', ['contentType' => 'text/plain']);
    expect($files->download('c/four.txt'))->toBe('four');

    // Public URL only works once the bucket is public.
    $publicUrl = $files->getPublicUrl('a/one.txt');
    expect($http->get($publicUrl)->getStatusCode())->toBe(400);
    $storage->updateBucket($bucketId, ['public' => true]);
    expect($storage->getBucket($bucketId)->public)->toBeTrue();
    $public = $http->get($publicUrl);
    expect($public->getStatusCode())->toBe(200)
        ->and((string) $public->getBody())->toBe('one');

    $storage->emptyBucket($bucketId);
    expect($names($files->list('a')))->toBe([])
        ->and($names($files->list('c')))->toBe([]);
    $storage->deleteBucket($bucketId);
});
