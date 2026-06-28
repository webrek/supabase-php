<?php

declare(strict_types=1);

namespace Supabase\Exception;

class AuthException extends SupabaseException
{
    protected static function extraRedactionKeys(): array
    {
        return ['provider_token', 'id_token', 'hashed_token', 'email_otp', 'action_link', 'recovery_token'];
    }
}
