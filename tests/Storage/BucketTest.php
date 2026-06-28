<?php

declare(strict_types=1);

namespace Supabase\Tests\Storage;

use Supabase\Storage\Bucket;

test('Bucket::fromArray maps storage fields', function () {
    $b = Bucket::fromArray([
        'id' => 'avatars',
        'name' => 'avatars',
        'public' => true,
        'file_size_limit' => 1048576,
        'allowed_mime_types' => ['image/png', 'image/jpeg'],
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-02T00:00:00Z',
    ]);

    expect($b->id)->toBe('avatars')
        ->and($b->name)->toBe('avatars')
        ->and($b->public)->toBeTrue()
        ->and($b->fileSizeLimit)->toBe(1048576)
        ->and($b->allowedMimeTypes)->toBe(['image/png', 'image/jpeg'])
        ->and($b->createdAt)->toBe('2026-01-01T00:00:00Z')
        ->and($b->updatedAt)->toBe('2026-01-02T00:00:00Z');
});

test('Bucket::fromArray tolerates a minimal/ private payload', function () {
    $b = Bucket::fromArray(['id' => 'docs', 'name' => 'docs']);
    expect($b->public)->toBeFalse()
        ->and($b->fileSizeLimit)->toBeNull()
        ->and($b->allowedMimeTypes)->toBe([]);
});
