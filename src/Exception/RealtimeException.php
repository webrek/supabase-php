<?php

declare(strict_types=1);

namespace Supabase\Exception;

class RealtimeException extends SupabaseException
{
    protected static function redactResponseBody(string $body): string
    {
        // Fall back to the raw body on a PCRE error (preg_replace returns null).
        return preg_replace(
            '/"(apikey|access_token|refresh_token|token)"\s*:\s*"[^"]*"/i',
            '"$1":"***redacted***"',
            $body,
        ) ?? $body;
    }
}
