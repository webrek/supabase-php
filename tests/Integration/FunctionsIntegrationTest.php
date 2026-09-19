<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use Supabase\Exception\FunctionsException;

/**
 * Edge Functions against the stack's edge-runtime, which serves
 * supabase/functions/hello: a JSON round-trip and a missing function.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('Functions: invoke() round-trips JSON through a real edge function and surfaces a missing one', function (): void {
    $functions = IntegrationSupport::authClient()->functions();

    expect($functions->invoke('hello', ['body' => ['name' => 'PHP']]))->toBe(['message' => 'Hello PHP!'])
        ->and($functions->invoke('hello'))->toBe(['message' => 'Hello world!'])
        ->and(fn () => $functions->invoke('does-not-exist'))->toThrow(FunctionsException::class);
});
