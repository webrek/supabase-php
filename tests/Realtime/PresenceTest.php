<?php

declare(strict_types=1);

namespace Supabase\Tests\Realtime;

use Supabase\Realtime\Presence;

test('syncState transforms metas, renames phx_ref to presence_ref, drops phx_ref_prev', function () {
    $p = new Presence();
    $p->syncState(['u1' => ['metas' => [['phx_ref' => 'r1', 'phx_ref_prev' => 'r0', 'name' => 'ada']]]]);

    expect($p->state())->toBe(['u1' => [['presence_ref' => 'r1', 'name' => 'ada']]]);
});

test('syncState fires onJoin for new keys with currentBefore empty', function () {
    $p = new Presence();
    $joins = [];
    $p->syncState(
        ['u1' => ['metas' => [['phx_ref' => 'r1', 'name' => 'ada']]]],
        function (string $key, array $cur, array $new) use (&$joins): void {
            $joins[] = [$key, $cur, $new];
        }
    );
    expect($joins)->toHaveCount(1)
        ->and($joins[0][0])->toBe('u1')
        ->and($joins[0][1])->toBe([])
        ->and($joins[0][2])->toBe([['presence_ref' => 'r1', 'name' => 'ada']]);
});

test('syncDiff adds joins and removes leaves, deleting empty keys', function () {
    $p = new Presence();
    $p->syncState(['u1' => ['metas' => [['phx_ref' => 'r1']]], 'u2' => ['metas' => [['phx_ref' => 'r2']]]]);

    $left = [];
    $p->syncDiff(
        ['u3' => ['metas' => [['phx_ref' => 'r3']]]],
        ['u1' => ['metas' => [['phx_ref' => 'r1']]]],
        null,
        function (string $key, array $cur, array $leftP) use (&$left): void {
            $left[] = [$key, $cur, $leftP];
        }
    );

    expect($p->state())->toBe([
        'u2' => [['presence_ref' => 'r2']],
        'u3' => [['presence_ref' => 'r3']],
    ])
        ->and($left)->toHaveCount(1)
        ->and($left[0][0])->toBe('u1')
        ->and($left[0][1])->toBe([])               // remaining after removal
        ->and($left[0][2])->toBe([['presence_ref' => 'r1']]); // left
});

test('syncState computes leaves for keys that disappear', function () {
    $p = new Presence();
    $p->syncState(['u1' => ['metas' => [['phx_ref' => 'r1']]]]);
    $p->syncState([]); // u1 gone
    expect($p->state())->toBe([]);
});

test('syncDiff join keeps surviving presences with different refs (prepended)', function () {
    $p = new Presence();
    $p->syncState(['u1' => ['metas' => [['phx_ref' => 'r1']]]]);
    $p->syncDiff(['u1' => ['metas' => [['phx_ref' => 'r2']]]], []);
    expect($p->state())->toBe(['u1' => [['presence_ref' => 'r1'], ['presence_ref' => 'r2']]]);
});

test('syncState on an existing key reports only the refs that joined and left, and keeps the rest', function () {
    $p = new Presence();
    $p->syncState(['u1' => ['metas' => [['phx_ref' => 'r1'], ['phx_ref' => 'r2']]]]);

    $joins = [];
    $leaves = [];
    $p->syncState(
        ['u1' => ['metas' => [['phx_ref' => 'r2'], ['phx_ref' => 'r3']]]],
        function (string $key, array $cur, array $new) use (&$joins): void {
            $joins[] = [$key, $new];
        },
        function (string $key, array $cur, array $left) use (&$leaves): void {
            $leaves[] = [$key, $left];
        }
    );

    expect($joins)->toBe([['u1', [['presence_ref' => 'r3']]]])
        ->and($leaves)->toBe([['u1', [['presence_ref' => 'r1']]]])
        ->and($p->state())->toBe(['u1' => [['presence_ref' => 'r2'], ['presence_ref' => 'r3']]]);
});

test('syncState with an identical snapshot fires neither callback', function () {
    $p = new Presence();
    $snapshot = ['u1' => ['metas' => [['phx_ref' => 'r1']]]];
    $p->syncState($snapshot);

    $fired = 0;
    $p->syncState($snapshot, function () use (&$fired): void {
        $fired++;
    }, function () use (&$fired): void {
        $fired++;
    });

    expect($fired)->toBe(0);
});

test('syncDiff ignores leaves for unknown keys and metas that are not objects', function () {
    $p = new Presence();
    $p->syncState(['u1' => ['metas' => [['phx_ref' => 'r1'], 'garbage', 42]]]);
    expect($p->state())->toBe(['u1' => [['presence_ref' => 'r1']]]);

    $left = [];
    $p->syncDiff([], ['ghost' => ['metas' => [['phx_ref' => 'x']]]], null, function (string $key) use (&$left): void {
        $left[] = $key;
    });

    expect($left)->toBe([])
        ->and($p->state())->toBe(['u1' => [['presence_ref' => 'r1']]]);
});
