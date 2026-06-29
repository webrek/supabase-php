<?php

declare(strict_types=1);

use Supabase\Tests\Integration\IntegrationSupport;

test('PostgREST: insert, select, update, and delete round-trip against integration_items', function (): void {
    $client = IntegrationSupport::client();
    $name = 'item_' . uniqid();
    $updatedName = $name . '_updated';

    // INSERT a row and return it so we can read the generated id.
    $insertResult = $client->from('integration_items')
        ->insert(['name' => $name])
        ->select('id,name')
        ->execute();

    $inserted = IntegrationSupport::firstRow($insertResult);
    expect($inserted['name'])->toBe($name);

    // SELECT it back by name to confirm the wire round-trip.
    $selectResult = $client->from('integration_items')
        ->select('id,name')
        ->eq('name', $name)
        ->execute();

    $selected = IntegrationSupport::firstRow($selectResult);
    expect($selected['name'])->toBe($name)
        ->and($selected['id'])->toBe($inserted['id']);

    // UPDATE using the name as the filter (avoids a mixed-type id comparison).
    $client->from('integration_items')
        ->update(['name' => $updatedName])
        ->eq('name', $name)
        ->execute();

    // Verify the update persisted.
    $afterUpdate = IntegrationSupport::firstRow(
        $client->from('integration_items')
            ->select('name')
            ->eq('name', $updatedName)
            ->execute()
    );
    expect($afterUpdate['name'])->toBe($updatedName);

    // DELETE the row.
    $client->from('integration_items')
        ->delete()
        ->eq('name', $updatedName)
        ->execute();

    // Verify the row is gone.
    $afterDelete = $client->from('integration_items')
        ->select('id')
        ->eq('name', $updatedName)
        ->execute();

    assert(is_array($afterDelete));
    expect($afterDelete)->toBeEmpty();
});
