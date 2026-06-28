<?php

declare(strict_types=1);

namespace Supabase\Http;

use Psr\Http\Message\StreamInterface;
use Supabase\Exception\SupabaseException;

/**
 * Reads PSR-7 response bodies with a hard upper bound to guard against
 * memory-exhaustion (DoS) from unexpectedly large or hostile responses.
 */
final class ResponseBody
{
    public const MAX_BYTES = 10_485_760;

    /**
     * Reads the entire stream in chunks, failing fast once the accumulated
     * length exceeds $maxBytes. Seekable streams are rewound first.
     *
     * @throws SupabaseException when the body exceeds $maxBytes
     */
    public static function read(StreamInterface $stream, int $maxBytes = self::MAX_BYTES): string
    {
        try {
            $size = $stream->getSize();
            if ($size !== null && $size > $maxBytes) {
                throw new SupabaseException("Response body exceeded {$maxBytes} bytes.");
            }

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $result = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $result .= $chunk;
                if (strlen($result) > $maxBytes) {
                    throw new SupabaseException("Response body exceeded {$maxBytes} bytes.");
                }
            }
        } catch (SupabaseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new SupabaseException('Failed to read response body: ' . $e->getMessage(), previous: $e);
        }

        return $result;
    }
}
