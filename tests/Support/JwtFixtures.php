<?php

declare(strict_types=1);

namespace Supabase\Tests\Support;

/**
 * Builds signed JWTs and matching JWKs from freshly generated OpenSSL keys,
 * so verification tests never depend on a real Supabase project.
 */
final class JwtFixtures
{
    /**
     * @return array{jwk: array<string, mixed>, sign: callable(string): string}
     */
    public static function es256(string $kid = 'key-es'): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        \assert($key !== false);
        $ec = self::details($key, 'ec');

        $jwk = [
            'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => $kid,
            'x' => self::b64url(self::field($ec, 'x')),
            'y' => self::b64url(self::field($ec, 'y')),
        ];
        $sign = static function (string $input) use ($key): string {
            openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256);
            \assert(is_string($der));

            return self::derSignatureToRaw($der);
        };

        return ['jwk' => $jwk, 'sign' => $sign];
    }

    /**
     * @return array{jwk: array<string, mixed>, sign: callable(string): string}
     */
    public static function rs256(string $kid = 'key-rs'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        \assert($key !== false);
        $rsa = self::details($key, 'rsa');

        $jwk = [
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid,
            'n' => self::b64url(self::field($rsa, 'n')),
            'e' => self::b64url(self::field($rsa, 'e')),
        ];
        $sign = static function (string $input) use ($key): string {
            openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
            \assert(is_string($sig));

            return $sig;
        };

        return ['jwk' => $jwk, 'sign' => $sign];
    }

    /**
     * @return callable(string): string
     */
    public static function hs256(string $secret): callable
    {
        return static fn (string $input): string => hash_hmac('sha256', $input, $secret, true);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     * @param callable(string): string $sign
     */
    public static function jwt(array $header, array $payload, callable $sign): string
    {
        $input = self::b64url((string) json_encode($header)) . '.' . self::b64url((string) json_encode($payload));

        return $input . '.' . self::b64url($sign($input));
    }

    /**
     * Standard GoTrue claims; $extra overrides or adds to them.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function payload(int $exp, array $extra = []): array
    {
        return $extra + [
            'iss' => 'https://demo.supabase.co/auth/v1',
            'sub' => 'user-1',
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'email' => 'a@b.com',
            'session_id' => 'sess-1',
            'is_anonymous' => false,
            'app_metadata' => ['provider' => 'email'],
            'user_metadata' => ['name' => 'Ada'],
            'iat' => $exp - 3600,
            'exp' => $exp,
        ];
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private static function details(\OpenSSLAsymmetricKey $key, string $section): array
    {
        $details = openssl_pkey_get_details($key);
        \assert($details !== false);
        $part = $details[$section] ?? null;
        \assert(is_array($part));
        $out = [];
        foreach ($part as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function field(array $section, string $name): string
    {
        $value = $section[$name] ?? null;
        \assert(is_string($value));

        return $value;
    }

    /** DER SEQUENCE { INTEGER r, INTEGER s } → r||s with each side left-padded to 32 bytes. */
    private static function derSignatureToRaw(string $der): string
    {
        $pos = 2; // 0x30, short-form length (P-256 signatures are < 128 bytes)
        $rLen = ord($der[$pos + 1]);
        $r = substr($der, $pos + 2, $rLen);
        $pos += 2 + $rLen;
        $sLen = ord($der[$pos + 1]);
        $s = substr($der, $pos + 2, $sLen);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
