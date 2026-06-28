<?php

declare(strict_types=1);

namespace Supabase\Auth;

final readonly class User
{
    /**
     * @param array<string,mixed> $appMetadata
     * @param array<string,mixed> $userMetadata
     */
    public function __construct(
        public string $id,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $role = null,
        public array $appMetadata = [],
        public array $userMetadata = [],
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $lastSignInAt = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string,mixed> $appMetadata */
        $appMetadata = isset($data['app_metadata']) && is_array($data['app_metadata']) ? $data['app_metadata'] : [];

        /** @var array<string,mixed> $userMetadata */
        $userMetadata = isset($data['user_metadata']) && is_array($data['user_metadata']) ? $data['user_metadata'] : [];

        return new self(
            id: isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '',
            email: isset($data['email']) && is_string($data['email']) && $data['email'] !== '' ? $data['email'] : null,
            phone: isset($data['phone']) && is_string($data['phone']) && $data['phone'] !== '' ? $data['phone'] : null,
            role: isset($data['role']) && is_string($data['role']) ? $data['role'] : null,
            appMetadata: $appMetadata,
            userMetadata: $userMetadata,
            createdAt: isset($data['created_at']) && is_string($data['created_at']) ? $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) && is_string($data['updated_at']) ? $data['updated_at'] : null,
            lastSignInAt: isset($data['last_sign_in_at']) && is_string($data['last_sign_in_at']) ? $data['last_sign_in_at'] : null,
        );
    }
}
