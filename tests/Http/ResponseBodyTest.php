<?php

declare(strict_types=1);

namespace Supabase\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Supabase\Exception\SupabaseException;
use Supabase\Http\ResponseBody;

test('read returns the full content when within the limit', function () {
    $stream = (new Psr17Factory())->createStream('hello world');

    expect(ResponseBody::read($stream))->toBe('hello world');
});

test('read returns content when exactly at a small limit boundary', function () {
    $stream = (new Psr17Factory())->createStream('abcde');

    expect(ResponseBody::read($stream, 5))->toBe('abcde');
});

test('read rewinds a seekable stream before reading', function () {
    $stream = (new Psr17Factory())->createStream('rewound');
    $stream->seek(3);

    expect(ResponseBody::read($stream))->toBe('rewound');
});

test('read throws SupabaseException when the body exceeds maxBytes', function () {
    $stream = (new Psr17Factory())->createStream(str_repeat('a', 100));

    expect(fn () => ResponseBody::read($stream, 10))
        ->toThrow(SupabaseException::class);
});

test('MAX_BYTES is 10 MiB', function () {
    expect(ResponseBody::MAX_BYTES)->toBe(10_485_760);
});
