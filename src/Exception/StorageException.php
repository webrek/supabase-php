<?php

declare(strict_types=1);

namespace Supabase\Exception;

class StorageException extends SupabaseException
{
    protected static function extraRedactionKeys(): array
    {
        return ['signedURL', 'signedUrl', 'key'];
    }
}
