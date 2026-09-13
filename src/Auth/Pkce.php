<?php

declare(strict_types=1);

namespace Supabase\Auth;

/**
 * PKCE pair (RFC 7636) for the server-side OAuth flow: send the challenge
 * with getOAuthSignInUrl(), keep the verifier in the PHP session, and hand
 * it to exchangeCodeForSession() in the callback.
 */
final readonly class Pkce
{
    public const METHOD = 's256';

    private function __construct(
        #[\SensitiveParameter] public string $verifier,
        public string $challenge,
    ) {
    }

    /** A fresh verifier of 43 unreserved characters (32 random bytes, base64url). */
    public static function generate(): self
    {
        return self::fromVerifier(self::base64Url(random_bytes(32)));
    }

    /** Rebuilds the pair from a verifier stored between the redirect and the callback. */
    public static function fromVerifier(#[\SensitiveParameter] string $verifier): self
    {
        $length = strlen($verifier);
        if ($length < 43 || $length > 128 || preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) !== 1) {
            throw new \InvalidArgumentException(
                'A PKCE verifier must be 43-128 characters from [A-Za-z0-9-._~] (RFC 7636 §4.1).'
            );
        }

        return new self($verifier, self::base64Url(hash('sha256', $verifier, true)));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['verifier' => '***redacted***', 'challenge' => $this->challenge];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Pkce must not be serialized; store $verifier as a plain string instead.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Pkce must not be unserialized; rebuild it with Pkce::fromVerifier().');
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
