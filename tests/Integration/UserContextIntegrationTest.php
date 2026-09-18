<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

use Supabase\Auth\Session;
use Supabase\Exception\PostgrestException;

/**
 * End-to-end check that Client::withSession() / withAccessToken() make Row Level
 * Security apply as the bound user against a real PostgREST + GoTrue: a user
 * sees only their own private_notes, another user sees none, anon sees none,
 * and forging the owner column is refused by the policy.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */
test('RLS: withSession() scopes PostgREST queries to the signed-in user', function (): void {
    $anon = IntegrationSupport::authClient();
    $alice = IntegrationSupport::signUp($anon);
    $bob = IntegrationSupport::signUp($anon);

    $asAlice = $anon->withSession($alice);
    $asBob = $anon->withSession($bob);

    $body = 'note-' . uniqid();
    $inserted = IntegrationSupport::firstRow(
        $asAlice->from('private_notes')->insert(['body' => $body])->select('id,owner,body')->execute()
    );
    expect($inserted['owner'])->toBe($alice->user->id)
        ->and($inserted['body'])->toBe($body);

    $aliceRows = $asAlice->from('private_notes')->select('body')->eq('body', $body)->execute();
    $bobRows = $asBob->from('private_notes')->select('body')->eq('body', $body)->execute();
    $anonRows = $anon->from('private_notes')->select('body')->eq('body', $body)->execute();

    expect($aliceRows)->toBe([['body' => $body]])
        ->and($bobRows)->toBe([])
        ->and($anonRows)->toBe([]);

    // The owner column is checked by the INSERT policy: Bob cannot write a note as Alice.
    expect(fn () => $asBob->from('private_notes')->insert(['body' => 'forged', 'owner' => $alice->user->id])->execute())
        ->toThrow(PostgrestException::class);
});

test('withSession() refreshes an expired session against GoTrue and hands back the new one', function (): void {
    $anon = IntegrationSupport::authClient();
    $session = IntegrationSupport::signUp($anon);

    // Same real tokens, but pretend the access token expired a minute ago.
    $stale = new Session(
        accessToken: $session->accessToken,
        refreshToken: $session->refreshToken,
        expiresIn: 0,
        expiresAt: time() - 60,
        tokenType: 'bearer',
        user: $session->user,
    );

    $seen = [];
    $asUser = $anon->withSession($stale, function (Session $fresh) use (&$seen): void {
        $seen[] = $fresh;
    });

    expect($seen)->toHaveCount(1);
    $fresh = $seen[0] ?? null;
    \assert($fresh instanceof Session);
    expect($fresh->accessToken)->not->toBe($session->accessToken)
        ->and($fresh->refreshToken)->not->toBe('')
        ->and($fresh->isExpired())->toBeFalse()
        ->and($fresh->user->id)->toBe($session->user->id);

    // The sibling client really carries the refreshed token: GoTrue and PostgREST accept it.
    expect($anon->auth()->getUser($fresh->accessToken)->id)->toBe($session->user->id);
    $rows = $asUser->from('private_notes')->select('id')->limit(1)->execute();
    expect($rows)->toBeArray();
});
