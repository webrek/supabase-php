<?php

declare(strict_types=1);

namespace Supabase\Auth;

/**
 * Claims of a verified Supabase access token. Typed accessors cover the
 * claims GoTrue always issues; $raw holds the complete decoded payload.
 */
final readonly class Claims
{
    /**
     * @param string|list<string>|null $aud
     * @param array<string,mixed> $appMetadata
     * @param array<string,mixed> $userMetadata
     * @param array<mixed> $raw
     */
    public function __construct(
        public string $sub,
        public ?string $role,
        public ?string $email,
        public ?string $phone,
        public ?string $sessionId,
        public string|array|null $aud,
        public ?string $iss,
        public ?int $exp,
        public ?int $iat,
        public bool $isAnonymous,
        public array $appMetadata,
        public array $userMetadata,
        public array $raw,
    ) {
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $appMetadata = [];
        if (isset($payload['app_metadata']) && is_array($payload['app_metadata'])) {
            foreach ($payload['app_metadata'] as $k => $v) {
                $appMetadata[(string) $k] = $v;
            }
        }

        $userMetadata = [];
        if (isset($payload['user_metadata']) && is_array($payload['user_metadata'])) {
            foreach ($payload['user_metadata'] as $k => $v) {
                $userMetadata[(string) $k] = $v;
            }
        }

        $aud = null;
        if (isset($payload['aud']) && is_string($payload['aud'])) {
            $aud = $payload['aud'];
        } elseif (isset($payload['aud']) && is_array($payload['aud'])) {
            $aud = [];
            foreach ($payload['aud'] as $item) {
                if (is_string($item)) {
                    $aud[] = $item;
                }
            }
        }

        return new self(
            sub: isset($payload['sub']) && is_scalar($payload['sub']) ? (string) $payload['sub'] : '',
            role: isset($payload['role']) && is_string($payload['role']) ? $payload['role'] : null,
            email: isset($payload['email']) && is_string($payload['email']) && $payload['email'] !== '' ? $payload['email'] : null,
            phone: isset($payload['phone']) && is_string($payload['phone']) && $payload['phone'] !== '' ? $payload['phone'] : null,
            sessionId: isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : null,
            aud: $aud,
            iss: isset($payload['iss']) && is_string($payload['iss']) ? $payload['iss'] : null,
            exp: isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : null,
            iat: isset($payload['iat']) && is_numeric($payload['iat']) ? (int) $payload['iat'] : null,
            isAnonymous: isset($payload['is_anonymous']) && $payload['is_anonymous'] === true,
            appMetadata: $appMetadata,
            userMetadata: $userMetadata,
            raw: $payload,
        );
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->exp !== null && $this->exp <= ($now ?? time());
    }
}
