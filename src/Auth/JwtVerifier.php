<?php

declare(strict_types=1);

namespace Supabase\Auth;

use Supabase\Exception\AuthException;

/**
 * Verifies Supabase access tokens locally: ES256 / RS256 against a JWK from
 * the project's key set, HS256 against the legacy shared secret. Internal —
 * use GoTrueClient::getClaims().
 *
 * @internal
 */
final class JwtVerifier
{
    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    private const OID_EC_PUBLIC_KEY = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";

    private const OID_PRIME256V1 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    /**
     * Splits and decodes a compact JWS without verifying it.
     *
     * @return array{header: array<mixed>, payload: array<mixed>, signature: string, signingInput: string}
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new AuthException('Invalid JWT: expected three dot-separated segments.');
        }
        [$h, $p, $s] = $parts;

        $signature = self::base64UrlDecode($s);
        if ($signature === '') {
            throw new AuthException('Invalid JWT: empty signature.');
        }

        return [
            'header' => self::decodeJson(self::base64UrlDecode($h), 'header'),
            'payload' => self::decodeJson(self::base64UrlDecode($p), 'payload'),
            'signature' => $signature,
            'signingInput' => $h . '.' . $p,
        ];
    }

    /**
     * @param array<mixed> $jwk
     */
    public static function verifyWithJwk(string $signingInput, string $signature, string $alg, array $jwk): void
    {
        if (isset($jwk['alg']) && $jwk['alg'] !== $alg) {
            throw new AuthException('JWT signature verification failed: key algorithm mismatch.');
        }

        $pem = match ($alg) {
            'ES256' => self::ecJwkToPem($jwk),
            'RS256' => self::rsaJwkToPem($jwk),
            default => throw new AuthException("Unsupported JWT algorithm \"{$alg}\" for a JWK."),
        };
        $der = $alg === 'ES256' ? self::rawToDerSignature($signature) : $signature;

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new AuthException('Invalid JWK: could not build a public key from it.');
        }
        if (openssl_verify($signingInput, $der, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new AuthException('JWT signature verification failed.');
        }
    }

    public static function verifyWithSecret(string $signingInput, string $signature, #[\SensitiveParameter] string $secret): void
    {
        $expected = hash_hmac('sha256', $signingInput, $secret, true);
        if (! hash_equals($expected, $signature)) {
            throw new AuthException('JWT signature verification failed.');
        }
    }

    /**
     * @param array<mixed> $payload
     */
    public static function assertTimeClaims(array $payload, ?int $now = null): void
    {
        $now ??= time();

        $exp = $payload['exp'] ?? null;
        if (! is_numeric($exp)) {
            throw new AuthException('Invalid JWT: missing "exp" claim.');
        }
        if ((int) $exp <= $now) {
            throw new AuthException('JWT has expired.');
        }

        $nbf = $payload['nbf'] ?? null;
        if (is_numeric($nbf) && (int) $nbf > $now) {
            throw new AuthException('JWT is not valid yet ("nbf" is in the future).');
        }
    }

    public static function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new AuthException('Invalid JWT: malformed base64url segment.');
        }

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private static function decodeJson(string $json, string $what): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AuthException("Invalid JWT: {$what} is not valid JSON.", previous: $e);
        }
        if (! is_array($decoded)) {
            throw new AuthException("Invalid JWT: {$what} is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $jwk
     */
    private static function jwkString(array $jwk, string $name): string
    {
        $value = $jwk[$name] ?? null;
        if (! is_string($value) || $value === '') {
            throw new AuthException("Invalid JWK: missing \"{$name}\".");
        }

        return $value;
    }

    /**
     * RFC 7518 §6.3 RSA JWK → SubjectPublicKeyInfo PEM.
     *
     * @param array<mixed> $jwk
     */
    private static function rsaJwkToPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            throw new AuthException('Invalid JWK: RS256 requires kty "RSA".');
        }
        $n = self::base64UrlDecode(self::jwkString($jwk, 'n'));
        $e = self::base64UrlDecode(self::jwkString($jwk, 'e'));

        $rsaPublicKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $algorithm = self::derSequence(self::OID_RSA_ENCRYPTION . "\x05\x00");

        return self::pem(self::derSequence($algorithm . self::derBitString($rsaPublicKey)));
    }

    /**
     * RFC 7518 §6.2 EC JWK (P-256) → SubjectPublicKeyInfo PEM.
     *
     * @param array<mixed> $jwk
     */
    private static function ecJwkToPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
            throw new AuthException('Invalid JWK: ES256 requires kty "EC" and crv "P-256".');
        }
        $x = self::base64UrlDecode(self::jwkString($jwk, 'x'));
        $y = self::base64UrlDecode(self::jwkString($jwk, 'y'));
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new AuthException('Invalid JWK: P-256 coordinates must be 32 bytes each.');
        }

        $algorithm = self::derSequence(self::OID_EC_PUBLIC_KEY . self::OID_PRIME256V1);

        return self::pem(self::derSequence($algorithm . self::derBitString("\x04" . $x . $y)));
    }

    /**
     * JWS ES256 signatures are r||s (RFC 7518 §3.4); OpenSSL expects the
     * DER SEQUENCE { INTEGER r, INTEGER s } form.
     */
    private static function rawToDerSignature(string $raw): string
    {
        if (strlen($raw) !== 64) {
            throw new AuthException('Invalid JWT: an ES256 signature must be 64 bytes.');
        }

        return self::derSequence(self::derInteger(substr($raw, 0, 32)) . self::derInteger(substr($raw, 32)));
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derBitString(string $content): string
    {
        return "\x03" . self::derLength(strlen($content) + 1) . "\x00" . $content;
    }

    /** Unsigned big-endian magnitude → DER INTEGER (0x00 prefix when the high bit is set). */
    private static function derInteger(string $magnitude): string
    {
        $magnitude = ltrim($magnitude, "\x00");
        if ($magnitude === '' || (ord($magnitude[0]) & 0x80) !== 0) {
            $magnitude = "\x00" . $magnitude;
        }

        return "\x02" . self::derLength(strlen($magnitude)) . $magnitude;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
