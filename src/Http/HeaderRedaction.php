<?php

declare(strict_types=1);

namespace Supabase\Http;

/**
 * Shared redaction policy for HTTP header maps so that debug dumps
 * (var_dump / print_r / crash reporters) never expose live credentials.
 */
final class HeaderRedaction
{
    public const REDACTED = '***redacted***';

    /** @var list<string> */
    private const SENSITIVE_NAMES = ['authorization', 'apikey', 'cookie', 'proxy-authorization', 'x-api-key'];

    /** @var list<string> */
    private const SENSITIVE_SUBSTRINGS = ['auth', 'token', 'secret', 'key', 'cookie'];

    public static function isSensitive(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, self::SENSITIVE_NAMES, true)) {
            return true;
        }
        foreach (self::SENSITIVE_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function redact(array $headers): array
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            $safe[$name] = self::isSensitive($name) ? self::REDACTED : $value;
        }

        return $safe;
    }
}
