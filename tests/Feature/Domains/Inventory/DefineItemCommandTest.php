<?php

declare(strict_types=1);

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Model\Item;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('inventory:define-item creates a catalog entry at version 1 and audits', function () {
    ['tenant' => $tenant] = inventoryFixture();
    $t = (string) $tenant->id;

    Artisan::call('inventory:define-item', [
        'action' => 'define', '--tenant' => $t,
        '--code' => 'para-500', '--name' => 'Paracetamol 500mg', '--type' => 'medication', '--uom' => 'tablet',
        '--track-batch' => '1', '--track-expiry' => '1',
    ]);

    $item = TenantContextScope::run($t, null, fn () => Item::query()->where('code', 'PARA-500')->first());
    expect($item)->not->toBeNull()
        ->and($item->item_type)->toBe('MEDICATION')
        ->and($item->uom)->toBe('TABLET')
        ->and($item->track_batch)->toBeTrue()
        ->and($item->track_expiry)->toBeTrue()
        ->and((int) $item->version)->toBe(1);

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('action', 'inventory.item_defined')->exists()))->toBeTrue();
});

test('inventory:define-item update bumps the version in place', function () {
    ['tenant' => $tenant] = inventoryFixture();
    $t = (string) $tenant->id;
    $item = seedInventoryItem($t, ['code' => 'GAUZE', 'name' => 'Gauze']);

    Artisan::call('inventory:define-item', [
        'action' => 'update', '--tenant' => $t, '--id' => (string) $item->id, '--name' => 'Gauze swab 10cm',
    ]);

    $fresh = TenantContextScope::run($t, null, fn () => Item::query()->find($item->id));
    expect($fresh->name)->toBe('Gauze swab 10cm')->and((int) $fresh->version)->toBe(2);
});

test('inventory:define-item rejects a duplicate active code', function () {
    ['tenant' => $tenant] = inventoryFixture();
    $t = (string) $tenant->id;
    seedInventoryItem($t, ['code' => 'DUP']);

    expect(fn () => Artisan::call('inventory:define-item', [
        'action' => 'define', '--tenant' => $t,
        '--code' => 'dup', '--name' => 'X', '--type' => 'PPE', '--uom' => 'piece',
    ]))->toThrow(InventoryCatalogConflict::class);
});

test('inventory:define-item rejects track-expiry without track-batch', function () {
    ['tenant' => $tenant] = inventoryFixture();
    $t = (string) $tenant->id;

    expect(fn () => Artisan::call('inventory:define-item', [
        'action' => 'define', '--tenant' => $t,
        '--code' => 'BADFLAGS', '--name' => 'Y', '--type' => 'MEDICATION', '--uom' => 'vial', '--track-expiry' => '1',
    ]))->toThrow(InvalidArgumentException::class);
});
