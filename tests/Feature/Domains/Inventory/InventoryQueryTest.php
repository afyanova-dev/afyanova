<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\CreateStockAdjustment;
use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\RecordConsumption;
use App\Domains\Inventory\Application\Query\GetAvailableStock;
use App\Domains\Inventory\Application\Query\GetItem;
use App\Domains\Inventory\Application\Query\GetItems;
use App\Domains\Inventory\Application\Query\GetStockAdjustments;
use App\Domains\Inventory\Application\Query\GetStockBalance;
use App\Domains\Inventory\Application\Query\GetStockByFacility;
use App\Domains\Inventory\Application\Query\GetStockLedger;
use App\Domains\Inventory\Application\Query\GetStockLocations;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function askInv(string $t, ?string $f, object $query): mixed
{
    return TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch($query));
}

test('GetItem / GetItems / GetStockLocations read the catalogs', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $item = seedInventoryItem($t, ['code' => 'AMOX', 'item_type' => 'MEDICATION']);
    seedInventoryItem($t, ['code' => 'GLOVE', 'item_type' => 'PPE']);
    seedStockLocation($t, $f, ['code' => 'STORE-1']);

    expect(askInv($t, $f, new GetItem(itemId: (string) $item->id))->code)->toBe('AMOX');
    expect(askInv($t, $f, new GetItems(itemType: 'MEDICATION')))->toHaveCount(1);
    expect(askInv($t, $f, new GetStockLocations(facilityId: $f)))->toHaveCount(1);
});

test('GetStockBalance, GetAvailableStock and GetStockLedger reflect the movements with a running balance', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);

    dispatchInv($t, $f, new ReceiveStock(movementId: (string) Str::uuid7(), actorId: $a, itemId: (string) $item->id, locationId: (string) $location->id, quantity: '120'));
    dispatchInv($t, $f, new RecordConsumption(movementId: (string) Str::uuid7(), actorId: $a, itemId: (string) $item->id, locationId: (string) $location->id, quantity: '20'));

    $balance = askInv($t, $f, new GetStockBalance(itemId: (string) $item->id, locationId: (string) $location->id));
    expect($balance)->toHaveCount(1)->and($balance[0]->availableQty)->toBe('100.0000');

    $available = askInv($t, $f, new GetAvailableStock(itemId: (string) $item->id, facilityId: $f));
    expect($available->totalAvailableQty)->toBe('100.0000')->and($available->byLocation)->toHaveCount(1);

    $ledger = askInv($t, $f, new GetStockLedger(itemId: (string) $item->id, locationId: (string) $location->id));
    expect($ledger->entries)->toHaveCount(2)
        ->and($ledger->entries[0]->runningBalance)->toBe('120.0000')
        ->and($ledger->entries[1]->runningBalance)->toBe('100.0000')
        ->and($ledger->drift)->toBe('0.0000');
});

test('department-scoped inventory access only exposes stock in that department locations', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $item = seedInventoryItem($t);
    $pharmacyDepartmentId = (string) Str::uuid7();
    $laboratoryDepartmentId = (string) Str::uuid7();

    TenantContextScope::run($t, null, function () use ($t, $f, $user, $pharmacyDepartmentId, $laboratoryDepartmentId): void {
        foreach ([
            [$pharmacyDepartmentId, 'Pharmaceutical', 'PHD'],
            [$laboratoryDepartmentId, 'Laboratory', 'LAB'],
        ] as [$id, $name, $code]) {
            DB::table('org_departments')->insert([
                'id' => $id,
                'tenant_id' => $t,
                'facility_id' => $f,
                'name' => $name,
                'code' => $code,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('idn_access_assignments')
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->update(['department_id' => $pharmacyDepartmentId]);
    });

    $pharmacyLocation = seedStockLocation($t, $f, [
        'code' => 'PHARMACY',
        'department_id' => $pharmacyDepartmentId,
    ]);
    $laboratoryLocation = seedStockLocation($t, $f, [
        'code' => 'LABORATORY',
        'department_id' => $laboratoryDepartmentId,
    ]);
    $sharedLocation = seedStockLocation($t, $f, ['code' => 'MAIN_STORE']);

    TenantContextScope::run($t, null, function () use ($t, $f, $item, $pharmacyLocation, $laboratoryLocation, $sharedLocation): void {
        foreach ([$pharmacyLocation, $laboratoryLocation, $sharedLocation] as $location) {
            StockBalance::factory()->forTenant($t)->onHand('10')->create([
                'facility_id' => $f,
                'item_id' => (string) $item->id,
                'location_id' => (string) $location->id,
            ]);
        }
    });

    $locations = askInv($t, $f, new GetStockLocations($f));
    $stockOnHand = askInv($t, $f, new GetStockByFacility($f));
    $available = askInv($t, $f, new GetAvailableStock((string) $item->id, $f));

    expect($locations)->toHaveCount(1)
        ->and($locations[0]->locationId)->toBe((string) $pharmacyLocation->id)
        ->and($stockOnHand->getCollection())->toHaveCount(1)
        ->and((string) $stockOnHand->getCollection()[0]->locationId)->toBe((string) $pharmacyLocation->id)
        ->and($available->byLocation)->toHaveCount(1)
        ->and($available->byLocation[0]->locationId)->toBe((string) $pharmacyLocation->id);

    expect(fn () => askInv($t, $f, new GetStockBalance((string) $item->id, (string) $laboratoryLocation->id)))
        ->toThrow(NotFound::class);
});

test('GetAvailableStock excludes expired batches from the totals and flags per-batch eligibility (Phase 4)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $item = seedInventoryItem($t, ['track_batch' => true, 'track_expiry' => true]);
    $location = seedStockLocation($t, $f);

    TenantContextScope::run($t, null, function () use ($t, $f, $item, $location): void {
        $expired = Batch::factory()->forTenant($t)->forItem((string) $item->id)
            ->create(['batch_number' => 'EXP-1', 'expiry_date' => CarbonImmutable::now()->subDay()->toDateString()]);
        $usable = Batch::factory()->forTenant($t)->forItem((string) $item->id)
            ->create(['batch_number' => 'OK-1', 'expiry_date' => CarbonImmutable::now()->addYear()->toDateString()]);

        StockBalance::factory()->forTenant($t)->onHand('40')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $location->id, 'batch_id' => (string) $expired->id,
        ]);
        StockBalance::factory()->forTenant($t)->onHand('60')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $location->id, 'batch_id' => (string) $usable->id,
        ]);
    });

    $available = askInv($t, $f, new GetAvailableStock(itemId: (string) $item->id, facilityId: $f));

    // Totals are eligible-only: the expired 40 is not available; the 60 is.
    expect($available->totalOnHandQty)->toBe('60.0000')
        ->and($available->totalReservedQty)->toBe('0.0000')
        ->and($available->totalAvailableQty)->toBe('60.0000')
        ->and($available->byLocation)->toHaveCount(2);

    $byBatch = collect($available->byLocation)->keyBy(fn ($row): string => (string) $row->batchNumber);
    expect($byBatch['EXP-1']->eligible)->toBeFalse()
        ->and($byBatch['EXP-1']->expiryDate)->not->toBeNull()
        ->and($byBatch['OK-1']->eligible)->toBeTrue();
});

test('GetStockAdjustments lists the facility worklist and is gated by inventory.access', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    dispatchInv($t, $f, new CreateStockAdjustment(
        adjustmentId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id,
        direction: 'INCREASE', quantity: '5', reasonCode: 'DATA_ERROR',
    ));

    $pending = askInv($t, $f, new GetStockAdjustments(facilityId: $f, status: 'PENDING_APPROVAL'));
    expect($pending)->toHaveCount(1)->and($pending[0]->reasonCode)->toBe('DATA_ERROR');
});

test('an inventory query without inventory.access is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture(permissions: []);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $item = seedInventoryItem($t);

    expect(fn () => askInv($t, $f, new GetItem(itemId: (string) $item->id)))
        ->toThrow(AuthorizationException::class);
});

test('InventoryApi::getItems returns narrow refs and filters by item type (Phase 5)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $med = seedInventoryItem($t, ['code' => 'AMOX', 'item_type' => 'MEDICATION']);
    seedInventoryItem($t, ['code' => 'GLOVE', 'item_type' => 'PPE']);

    TenantContextScope::run($t, $f, function () use ($med): void {
        $api = app(InventoryApi::class);

        $medications = $api->getItems('MEDICATION');
        expect($medications)->toHaveCount(1)
            ->and($medications[0]->itemId)->toBe((string) $med->id)
            ->and($medications[0]->code)->toBe('AMOX')
            ->and($medications[0]->itemType)->toBe('MEDICATION')
            ->and($medications[0]->active)->toBeTrue();

        expect($api->getItems())->toHaveCount(2);
        expect($api->getItems('PPE'))->toHaveCount(1);
    });
});
