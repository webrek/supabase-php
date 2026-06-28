<?php

declare(strict_types=1);

namespace Supabase\Exception;

class StorageException extends SupabaseException
{
    protected static function redactResponseBody(string $body): string
    {
        // Fall back to the raw body on a PCRE error (preg_replace returns null).
        return preg_replace(
            '/"(token|signedURL|signedUrl|key)"\s*:\s*"[^"]*"/',
            '"$1":"***redacted***"',
            $body,
        ) ?? $body;
    }
}
