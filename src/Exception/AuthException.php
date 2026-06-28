<?php

declare(strict_types=1);

namespace Supabase\Exception;

class AuthException extends SupabaseException
{
    protected static function redactResponseBody(string $body): string
    {
        // Fall back to the raw body on a PCRE error (preg_replace returns null) — an
        // empty body is a worse failure mode than an unredacted one.
        return preg_replace(
            '/"(access_token|refresh_token|provider_token|id_token)"\s*:\s*"[^"]*"/',
            '"$1":"***redacted***"',
            $body,
        ) ?? $body;
    }
}
