<?php

declare(strict_types=1);

namespace Supabase\Auth;

final readonly class Session implements \JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        #[\SensitiveParameter] public string $refreshToken,
        public ?int $expiresIn,
        public ?int $expiresAt,
        public string $tokenType,
        public User $user,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string,mixed> $userData */
        $userData = isset($data['user']) && is_array($data['user']) ? $data['user'] : [];

        return new self(
            accessToken: isset($data['access_token']) && is_string($data['access_token']) ? $data['access_token'] : '',
            refreshToken: isset($data['refresh_token']) && is_string($data['refresh_token']) ? $data['refresh_token'] : '',
            expiresIn: isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : null,
            expiresAt: isset($data['expires_at']) && is_numeric($data['expires_at']) ? (int) $data['expires_at'] : null,
            tokenType: isset($data['token_type']) && is_string($data['token_type']) ? $data['token_type'] : 'bearer',
            user: User::fromArray($userData),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '***redacted***',
            'refreshToken' => '***redacted***',
            'expiresIn' => $this->expiresIn,
            'expiresAt' => $this->expiresAt,
            'tokenType' => $this->tokenType,
            'user' => $this->user,
        ];
    }

    /**
     * Redacts tokens so json_encode() (loggers, PSR-3 context, API serializers) cannot leak them.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string,mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Session must not be serialized; it holds credentials.');
    }

    /**
     * @param array<string,mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Session must not be unserialized; it holds credentials.');
    }
}
