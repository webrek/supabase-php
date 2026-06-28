<?php

declare(strict_types=1);

namespace Supabase\Storage;

final readonly class Bucket
{
    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $public = false,
        public ?int $fileSizeLimit = null,
        public array $allowedMimeTypes = [],
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $mimes = [];
        if (isset($data['allowed_mime_types']) && is_array($data['allowed_mime_types'])) {
            foreach ($data['allowed_mime_types'] as $m) {
                if (is_string($m)) {
                    $mimes[] = $m;
                }
            }
        }

        return new self(
            id: isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '',
            name: isset($data['name']) && is_scalar($data['name']) ? (string) $data['name'] : '',
            public: isset($data['public']) && $data['public'] === true,
            fileSizeLimit: isset($data['file_size_limit']) && is_numeric($data['file_size_limit']) ? (int) $data['file_size_limit'] : null,
            allowedMimeTypes: $mimes,
            createdAt: isset($data['created_at']) && is_string($data['created_at']) ? $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) && is_string($data['updated_at']) ? $data['updated_at'] : null,
        );
    }
}
