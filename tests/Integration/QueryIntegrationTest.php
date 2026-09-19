<?php

declare(strict_types=1);

namespace Supabase\Tests\Integration;

/**
 * Every PostgREST filter and modifier the SDK exposes, against a real
 * PostgREST with the seeded integration_products catalogue: this is where an
 * operator spelled wrong on the wire shows up. Plus upsert, count and RPC.
 *
 * Skipped automatically when the SUPABASE_* env vars are absent (see tests/Pest.php).
 */

// The upsert test writes rows; a run that dies before its cleanup would shift
// every page and count below, so sweep them before each test.
beforeEach(function (): void {
    IntegrationSupport::client()->from('integration_products')->delete()->like('sku', 'sku-upsert-%')->execute();
});

/**
 * @param array<mixed>|null $rows
 * @return list<string>
 */
function skus(array|null $rows): array
{
    $out = [];
    foreach ($rows ?? [] as $row) {
        if (is_array($row) && is_string($row['sku'] ?? null)) {
            $out[] = $row['sku'];
        }
    }
    sort($out);

    return $out;
}

test('PostgREST: comparison, pattern, null, list, negation, or, match and generic filters', function (): void {
    $db = IntegrationSupport::client();
    $q = static fn () => $db->from('integration_products')->select('sku');

    expect(skus($q()->neq('sku', 'sku-phone')->execute()))->toBe(['sku-cable', 'sku-laptop', 'sku-tablet'])
        ->and(skus($q()->gt('price', 300)->execute()))->toBe(['sku-laptop', 'sku-phone'])
        ->and(skus($q()->gte('price', 299.5)->execute()))->toBe(['sku-laptop', 'sku-phone', 'sku-tablet'])
        ->and(skus($q()->lt('stock', 5)->execute()))->toBe(['sku-laptop', 'sku-tablet'])
        ->and(skus($q()->lte('stock', 3)->execute()))->toBe(['sku-laptop', 'sku-tablet'])
        ->and(skus($q()->like('name', 'Phone%')->execute()))->toBe(['sku-phone'])
        ->and(skus($q()->ilike('name', '%BETA')->execute()))->toBe(['sku-tablet'])
        ->and(skus($q()->is('discontinued', null)->execute()))->toBe(['sku-laptop'])
        ->and(skus($q()->is('discontinued', true)->execute()))->toBe(['sku-cable'])
        ->and(skus($q()->in('sku', ['sku-phone', 'sku-cable'])->execute()))->toBe(['sku-cable', 'sku-phone'])
        ->and(skus($q()->not('sku', 'eq', 'sku-phone')->execute()))->toBe(['sku-cable', 'sku-laptop', 'sku-tablet'])
        ->and(skus($q()->or('price.gt.1000,stock.gt.100')->execute()))->toBe(['sku-cable', 'sku-laptop'])
        ->and(skus($q()->match(['sku' => 'sku-phone', 'stock' => 10])->execute()))->toBe(['sku-phone'])
        ->and(skus($q()->filter('stock', 'gte', 10)->execute()))->toBe(['sku-cable', 'sku-phone']);
});

test('PostgREST: array, jsonb, range and full-text operators', function (): void {
    $db = IntegrationSupport::client();
    $q = static fn () => $db->from('integration_products')->select('sku');

    expect(skus($q()->contains('tags', ['mobile'])->execute()))->toBe(['sku-phone', 'sku-tablet'])
        ->and(skus($q()->containedBy('tags', ['mobile', '5g', 'pen'])->execute()))->toBe(['sku-phone', 'sku-tablet'])
        ->and(skus($q()->overlaps('tags', ['5g', 'pro'])->execute()))->toBe(['sku-laptop', 'sku-phone'])
        ->and(skus($q()->contains('meta', '{"color":"black"}')->execute()))->toBe(['sku-cable', 'sku-phone'])
        ->and(skus($q()->rangeGt('avail', '[10,20)')->execute()))->toBe(['sku-laptop'])
        ->and(skus($q()->rangeLt('avail', '[10,20)')->execute()))->toBe(['sku-phone'])
        ->and(skus($q()->rangeGte('avail', '[10,20)')->execute()))->toBe(['sku-laptop', 'sku-tablet'])
        ->and(skus($q()->rangeLte('avail', '[10,20)')->execute()))->toBe(['sku-cable', 'sku-phone', 'sku-tablet'])
        ->and(skus($q()->rangeAdjacent('avail', '[10,20)')->execute()))->toBe(['sku-laptop', 'sku-phone'])
        ->and(skus($q()->textSearch('name', 'Phone', 'english')->execute()))->toBe(['sku-phone']);
});

test('PostgREST: order, limit, range, single, maybeSingle and count', function (): void {
    $db = IntegrationSupport::client();
    $q = static fn () => $db->from('integration_products')->select('sku');

    // skus() sorts, so read the order from the raw rows here.
    $top = $q()->order('price', ascending: false)->limit(2)->execute();
    $page = $q()->order('price')->range(1, 2)->execute();
    $single = $db->from('integration_products')->select('sku,price')->eq('sku', 'sku-phone')->single()->execute();

    expect($top)->toBe([['sku' => 'sku-laptop'], ['sku' => 'sku-phone']])
        ->and($page)->toBe([['sku' => 'sku-tablet'], ['sku' => 'sku-phone']])
        ->and($single)->toBe(['sku' => 'sku-phone', 'price' => 499.0]) // numeric columns decode as floats
        ->and($q()->eq('sku', 'nope')->maybeSingle()->execute())->toBeNull()
        ->and($q()->eq('sku', 'sku-cable')->maybeSingle()->execute())->toBe(['sku' => 'sku-cable'])
        ->and($q()->gt('price', 100)->count())->toBe(3)
        ->and($q()->count())->toBeGreaterThanOrEqual(4);
});

test('PostgREST: upsert on a conflict target and set-returning RPC', function (): void {
    $db = IntegrationSupport::client();
    $sku = 'sku-upsert-' . uniqid();

    $db->from('integration_products')->upsert(['sku' => $sku, 'name' => 'Upsert A', 'price' => 1], 'sku')->execute();
    $db->from('integration_products')->upsert(['sku' => $sku, 'name' => 'Upsert B', 'price' => 2], 'sku')->execute();

    $rows = $db->from('integration_products')->select('name,price')->eq('sku', $sku)->execute();
    expect($rows)->toBe([['name' => 'Upsert B', 'price' => 2.0]]);

    $db->from('integration_products')->delete()->eq('sku', $sku)->execute();
    expect($db->from('integration_products')->select('sku')->eq('sku', $sku)->execute())->toBe([]);

    $tagged = $db->rpc('integration_products_tagged', ['tag' => 'mobile'])->select('sku')->execute();
    expect(skus($tagged))->toBe(['sku-phone', 'sku-tablet'])
        ->and($db->rpc('integration_add', ['a' => 2, 'b' => 3])->scalar())->toBe(5);
});
