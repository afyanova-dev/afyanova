<?php

declare(strict_types=1);

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

test('inventory:define-location creates a STORE with a null parent (small-facility shape) and audits', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    Artisan::call('inventory:define-location', [
        'action' => 'define', '--tenant' => $t, '--facility' => $f,
        '--code' => 'main-store', '--name' => 'Main Store', '--type' => 'store',
    ]);

    $location = TenantContextScope::run($t, null, fn () => StockLocation::query()->where('code', 'MAIN-STORE')->first());
    expect($location)->not->toBeNull()
        ->and($location->location_type)->toBe('STORE')
        ->and($location->parent_location_id)->toBeNull()
        ->and((string) $location->facility_id)->toBe($f)
        ->and((int) $location->version)->toBe(1);

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('action', 'inventory.stock_location_defined')->exists()))->toBeTrue();
});

test('inventory:define-location assigns an active facility department for scoped stock visibility', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $departmentId = (string) Str::uuid7();

    TenantContextScope::run($t, null, function () use ($t, $f, $departmentId): void {
        DB::table('org_departments')->insert([
            'id' => $departmentId,
            'tenant_id' => $t,
            'facility_id' => $f,
            'name' => 'Pharmaceutical',
            'code' => 'PHD',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(Artisan::call('inventory:define-location', [
        'action' => 'define', '--tenant' => $t, '--facility' => $f,
        '--code' => 'pharmacy', '--name' => 'Pharmacy / Dispensing Area',
        '--type' => 'STORE', '--department' => $departmentId,
    ]))->toBe(Command::SUCCESS);

    $location = TenantContextScope::run($t, null, fn () => StockLocation::query()->where('code', 'PHARMACY')->firstOrFail());
    expect((string) $location->department_id)->toBe($departmentId);
});

test('inventory:define-location rejects types that were not saved in its scope', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    Artisan::call('inventory:define-location', [
        'action' => 'define', '--tenant' => $t, '--facility' => $f,
        '--code' => 'bin-01', '--name' => 'Bin 01', '--type' => 'BIN',
    ]);

    expect(Artisan::output())->toContain('an available saved location type')
        ->and(Artisan::call('inventory:define-location', [
            'action' => 'define', '--tenant' => $t, '--facility' => $f,
            '--code' => 'store-2', '--name' => 'Store 2', '--type' => 'STORE',
        ]))->toBe(Command::SUCCESS);
});

test('inventory:define-location rejects a duplicate active code at the same facility', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    seedStockLocation($t, $f, ['code' => 'DUP']);

    expect(fn () => Artisan::call('inventory:define-location', [
        'action' => 'define', '--tenant' => $t, '--facility' => $f,
        '--code' => 'dup', '--name' => 'X', '--type' => 'STORE',
    ]))->toThrow(InventoryCatalogConflict::class);
});
