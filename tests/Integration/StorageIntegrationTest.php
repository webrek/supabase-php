<?php

declare(strict_types=1);

use Supabase\Tests\Integration\IntegrationSupport;

test('Storage: create bucket, upload, download, list, remove object, delete bucket', function (): void {
    $client = IntegrationSupport::client();
    $bucketId = 'test-' . uniqid();
    $objectPath = 'hello.txt';
    $contents = 'integration test payload ' . uniqid();

    // CREATE a private bucket with a unique name to avoid collisions across runs.
    $client->storage()->createBucket($bucketId);

    // UPLOAD a small text object.
    $client->storage()->from($bucketId)->upload($objectPath, $contents, ['contentType' => 'text/plain']);

    // DOWNLOAD the object and verify the bytes are identical.
    $downloaded = $client->storage()->from($bucketId)->download($objectPath);
    expect($downloaded)->toBe($contents);

    // LIST objects in the bucket — the uploaded file must appear.
    $listed = $client->storage()->from($bucketId)->list();

    $names = [];
    foreach ($listed as $file) {
        if (is_array($file) && isset($file['name']) && is_string($file['name'])) {
            $names[] = $file['name'];
        }
    }

    expect($names)->toContain($objectPath);

    // REMOVE the object.
    $client->storage()->from($bucketId)->remove([$objectPath]);

    // DELETE the bucket — storage requires the bucket to be empty first;
    // remove() above ensures that.
    $client->storage()->deleteBucket($bucketId);

    // Verify the bucket no longer appears in the listing.
    $buckets = $client->storage()->listBuckets();
    $bucketIds = array_map(static fn (\Supabase\Storage\Bucket $b): string => $b->id, $buckets);
    expect($bucketIds)->not->toContain($bucketId);
});
