<?php

declare(strict_types=1);

namespace Supabase\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Configurable PSR-7 stream test double.
 *
 * Lets tests drive ResponseBody through edge cases that real streams make
 * awkward to reproduce: a known oversized getSize(), and a read() that fails
 * mid-body with a \RuntimeException (as PSR-7 permits).
 */
final class FakeStream implements StreamInterface
{
    public int $readCalls = 0;

    private int $pos = 0;

    private bool $eofReached = false;

    public function __construct(
        private readonly string $content = '',
        private readonly ?int $size = null,
        private readonly bool $throwOnRead = false,
        private readonly bool $throwOnGetSize = false,
    ) {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
    }

    /**
     * @return resource|null
     */
    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        if ($this->throwOnGetSize) {
            throw new \RuntimeException('getSize boom');
        }

        return $this->size;
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function eof(): bool
    {
        return $this->eofReached;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->pos = $offset;
        $this->eofReached = false;
    }

    public function rewind(): void
    {
        $this->pos = 0;
        $this->eofReached = false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('FakeStream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $this->readCalls++;

        if ($this->throwOnRead) {
            throw new \RuntimeException('boom');
        }

        $chunk = substr($this->content, $this->pos, $length);
        $this->pos += strlen($chunk);

        if ($chunk === '') {
            $this->eofReached = true;
        }

        return $chunk;
    }

    public function getContents(): string
    {
        $remaining = substr($this->content, $this->pos);
        $this->pos = strlen($this->content);
        $this->eofReached = true;

        return $remaining;
    }

    /**
     * @return mixed
     */
    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
