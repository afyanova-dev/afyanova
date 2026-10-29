<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\CreateStockAdjustment;
use App\Domains\Inventory\Application\Query\GetStockLocationTypes;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\StockLocationTypeReader;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires inventory access and renders facility-scoped inventory options', function (): void {
    $this->get('/workspaces/inventory')->assertRedirect('/login');

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId, ['code' => 'MED-001', 'item_type' => 'MEDICATION']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY']);

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/inventory')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->where('canReceive', false)
            ->where('canManageItems', false)
            ->where('canManageLocations', false)
            ->where('canManageLocationTypes', false)
            ->has('locationTypes', 3)
            ->has('items', 1)
            ->where('items.0.id', (string) $item->id)
            ->where('items.0.category', $item->category)
            ->where('items.0.active', true)
            ->has('locations', 1)
            ->where('locations.0.id', (string) $location->id)
            ->where('locations.0.locationType', $location->location_type)
            ->where('locations.0.active', true));
});

it('shows aggregate stock per item when only a location is selected', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'LOCATION-FIRST']);
    $item = seedInventoryItem($tenantId, ['code' => 'LOCATION-ITEM', 'name' => 'Location test item']);
    $batch = TenantContextScope::run($tenantId, null, fn () => Batch::factory()
        ->forTenant($tenantId)
        ->forItem((string) $item->id)
        ->create([
            'batch_number' => 'LOCATION-BATCH-1',
            'expiry_date' => '2027-12-31',
        ]));

    TenantContextScope::run($tenantId, null, fn () => StockBalance::factory()
        ->forTenant($tenantId)
        ->onHand('100')
        ->create([
            'facility_id' => $facilityId,
            'item_id' => (string) $item->id,
            'location_id' => (string) $location->id,
            'batch_id' => (string) $batch->id,
            'reserved_qty' => '20',
        ]));

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get("/workspaces/inventory?section=stock&location_id={$location->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedItemId', null)
            ->where('selectedLocationId', (string) $location->id)
            ->has('locationItems', 1)
            ->where('locationItems.0.itemId', (string) $item->id)
            ->where('locationItems.0.itemCode', 'LOCATION-ITEM')
            ->where('locationItems.0.itemName', 'Location test item')
            ->where('locationItems.0.onHand', '100.0000')
            ->where('locationItems.0.reserved', '20.0000')
            ->where('locationItems.0.available', '80.0000')
            ->where('locationItems.0.uom', $item->uom)
            ->has('locationItems.0.batches', 1)
            ->where('locationItems.0.batches.0.batchId', (string) $batch->id)
            ->where('locationItems.0.batches.0.batchNumber', 'LOCATION-BATCH-1')
            ->where('locationItems.0.batches.0.expiryDate', '2027-12-31')
            ->where('locationItems.0.batches.0.onHand', '100.0000')
            ->where('locationItems.0.batches.0.reserved', '20.0000')
            ->where('locationItems.0.batches.0.available', '80.0000')
            ->has('balances', 0));
});

it('shows a department-scoped user their department and automatically selects its sole stock location', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $departmentId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $user, $departmentId): void {
        DB::table('org_departments')->insert([
            'id' => $departmentId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'name' => 'Pharmaceutical',
            'code' => 'PHD',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('idn_access_assignments')
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->update(['department_id' => $departmentId]);
    });

    $pharmacyLocation = seedStockLocation($tenantId, $facilityId, [
        'code' => 'PHARMACY',
        'department_id' => $departmentId,
    ]);
    seedStockLocation($tenantId, $facilityId, ['code' => 'LABORATORY']);

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/inventory?section=stock')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('departmentScoped', true)
            ->where('departmentName', 'Pharmaceutical')
            ->where('selectedLocationId', (string) $pharmacyLocation->id)
            ->has('locations', 1)
            ->where('locations.0.id', (string) $pharmacyLocation->id)
            ->has('locationItems', 0));
});

it('paginates and sorts location stock on the server and filters across all items', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'PAGED-LOCATION']);

    foreach (range(1, 17) as $quantity) {
        $item = seedInventoryItem($tenantId, [
            'code' => sprintf('PAGE-%02d', $quantity),
            'name' => sprintf('Paged item %02d', $quantity),
        ]);
        TenantContextScope::run($tenantId, null, fn () => StockBalance::factory()
            ->forTenant($tenantId)
            ->onHand((string) $quantity)
            ->create([
                'facility_id' => $facilityId,
                'item_id' => (string) $item->id,
                'location_id' => (string) $location->id,
            ]));
    }

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get("/workspaces/inventory?section=stock&location_id={$location->id}&page=2&per_page=15&sort_by=on_hand&sort_direction=desc")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('locationItems', 2)
            ->where('locationItems.0.itemCode', 'PAGE-02')
            ->where('locationItems.0.onHand', '2.0000')
            ->where('locationItems.1.itemCode', 'PAGE-01')
            ->where('locationItemPagination.currentPage', 2)
            ->where('locationItemPagination.lastPage', 2)
            ->where('locationItemPagination.perPage', 15)
            ->where('locationItemPagination.total', 17)
            ->where('locationItemPagination.sortBy', 'on_hand')
            ->where('locationItemPagination.sortDirection', 'desc'));

    $this->get("/workspaces/inventory?section=stock&location_id={$location->id}&q=PAGE-01")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('locationItems', 1)
            ->where('locationItems.0.itemCode', 'PAGE-01')
            ->where('locationItemPagination.total', 1)
            ->where('locationItemPagination.search', 'PAGE-01'));
});

it('lets catalogue managers deactivate and reactivate items and locations with audit records', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
        'inventory.locations.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);
    $item = seedInventoryItem($tenantId, ['code' => 'LIFECYCLE-ITEM']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'LIFECYCLE-LOCATION']);

    $this->actingAs($user)->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory?section=catalogue')
        ->patch("/workspaces/inventory/items/{$item->id}/status", ['active' => false])
        ->assertRedirect('/workspaces/inventory?section=catalogue')
        ->assertSessionHas('status', 'Inventory item deactivated.');

    $this->post('/facility-context', ['facility_id' => $facilityId])
        ->assertSessionHas('facility_context_mode', 'facility');
    $this->patch("/workspaces/inventory/locations/{$location->id}/status", ['active' => false])
        ->assertSessionHas('status', 'Stock location deactivated.');

    TenantContextScope::run($tenantId, null, function () use ($item, $location): void {
        expect(Item::query()->findOrFail($item->id)->active)->toBeFalse()
            ->and(StockLocation::query()->findOrFail($location->id)->active)->toBeFalse();
    });
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'action' => 'inventory.item_deactivated',
        'target_id' => (string) $item->id,
    ]);
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'action' => 'inventory.stock_location_deactivated',
        'target_id' => (string) $location->id,
    ]);

    $this->post('/facility-context', ['mode' => 'general']);
    $this->patch("/workspaces/inventory/items/{$item->id}/status", ['active' => true])
        ->assertSessionHas('status', 'Inventory item reactivated.');
    $this->post('/facility-context', ['facility_id' => $facilityId]);
    $this->patch("/workspaces/inventory/locations/{$location->id}/status", ['active' => true])
        ->assertSessionHas('status', 'Stock location reactivated.');
});

it('shows inactive catalogue items in General and inactive locations only in the selected facility', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
        'inventory.locations.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $inactiveItem = seedInventoryItem($tenantId, ['code' => 'INACTIVE-ITEM', 'active' => false]);
    $inactiveLocation = seedStockLocation($tenantId, $facilityId, ['code' => 'INACTIVE-LOCATION', 'active' => false]);
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $this->actingAs($user)->withSession(['facility_context_mode' => 'general'])
        ->get('/workspaces/inventory')
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageItems', true)
            ->where('canManageLocations', false)
            ->where('contextMode', 'general')
            ->where('items.0.id', (string) $inactiveItem->id)
            ->where('items.0.active', false)
            ->has('locations', 0));

    $this->post('/facility-context', ['facility_id' => $facilityId]);
    $this->get('/workspaces/inventory')
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageItems', false)
            ->where('canManageLocations', true)
            ->where('contextMode', 'facility')
            ->has('locations', 1)
            ->where('locations.0.id', (string) $inactiveLocation->id)
            ->where('locations.0.active', false));

    TenantContextScope::run($tenantId, null, function () use ($roleId): void {
        $permissionIds = DB::table('idn_permissions')
            ->whereIn('key', ['inventory.items.manage', 'inventory.locations.manage'])
            ->pluck('id');
        DB::table('idn_permission_role')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    });
    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/inventory')
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageItems', false)
            ->where('canManageLocations', false)
            ->has('items', 0)
            ->has('locations', 0));
});

it('prevents deactivating an item or location with on-hand or reserved stock', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
        'inventory.locations.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId, ['code' => 'STOCKED-ITEM']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'STOCKED-LOCATION']);
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);
    TenantContextScope::run($tenantId, null, fn () => StockBalance::factory()
        ->forTenant($tenantId)
        ->onHand('3')
        ->create([
            'facility_id' => $facilityId,
            'item_id' => (string) $item->id,
            'location_id' => (string) $location->id,
            'reserved_qty' => '1',
        ]));

    $this->actingAs($user)->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory')
        ->patch("/workspaces/inventory/items/{$item->id}/status", ['active' => false])
        ->assertRedirect('/workspaces/inventory')
        ->assertSessionHasErrors('item');
    $this->post('/facility-context', ['facility_id' => $facilityId]);
    $this->patch("/workspaces/inventory/locations/{$location->id}/status", ['active' => false])
        ->assertSessionHasErrors('location');

    TenantContextScope::run($tenantId, null, function () use ($item, $location): void {
        expect(Item::query()->findOrFail($item->id)->active)->toBeTrue()
            ->and(StockLocation::query()->findOrFail($location->id)->active)->toBeTrue();
    });
});

it('prevents reactivating item and location codes already in use', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
        'inventory.locations.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $inactiveItem = seedInventoryItem($tenantId, ['code' => 'REUSED-ITEM', 'active' => false]);
    seedInventoryItem($tenantId, ['code' => 'REUSED-ITEM']);
    $inactiveLocation = seedStockLocation($tenantId, $facilityId, [
        'code' => 'REUSED-LOCATION',
        'active' => false,
    ]);
    seedStockLocation($tenantId, $facilityId, ['code' => 'REUSED-LOCATION']);
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $this->actingAs($user)->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory')
        ->patch("/workspaces/inventory/items/{$inactiveItem->id}/status", ['active' => true])
        ->assertRedirect('/workspaces/inventory')
        ->assertSessionHasErrors('item');
    $this->post('/facility-context', ['facility_id' => $facilityId]);
    $this->patch("/workspaces/inventory/locations/{$inactiveLocation->id}/status", ['active' => true])
        ->assertSessionHasErrors('location');

    TenantContextScope::run($tenantId, null, function () use ($inactiveItem, $inactiveLocation): void {
        expect(Item::query()->findOrFail($inactiveItem->id)->active)->toBeFalse()
            ->and(StockLocation::query()->findOrFail($inactiveLocation->id)->active)->toBeFalse();
    });
});

it('lets a Facility Admin create a custom type scoped to the active facility', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.location_types.manage',
        'inventory.locations.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    TenantContextScope::run($tenantId, null, function () use ($roleId): void {
        DB::table('idn_roles')->where('id', $roleId)->update(['key' => 'facility_admin']);
    });

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/inventory')
        ->post('/workspaces/inventory/location-types', [
            'code' => 'PHARMACY',
            'name' => 'Pharmacy',
        ])
        ->assertRedirect(route('inventory.index'))
        ->assertSessionHas('status', 'Location type saved for this facility.');

    $type = TenantContextScope::run($tenantId, null, fn () => DB::table('inv_stock_location_types')
        ->where('tenant_id', $tenantId)
        ->where('code', 'PHARMACY')
        ->first());
    expect($type)->not->toBeNull()
        ->and((string) $type->facility_id)->toBe($facilityId);
    $otherFacility = TenantContextScope::run($tenantId, null, fn () => Facility::factory()
        ->forTenant($tenantId, (string) $organization->id)
        ->create());
    $visibleAtOtherFacility = TenantContextScope::run($tenantId, null, fn () => app(StockLocationTypeReader::class)
        ->listForTenantAndFacility($tenantId, (string) $otherFacility->id));
    expect(collect($visibleAtOtherFacility)->pluck('code')->all())->not->toContain('PHARMACY');
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'action' => 'inventory.stock_location_type_defined',
        'target_id' => (string) $type->id,
    ]);

    $this->post('/workspaces/inventory/locations', [
        'code' => 'PHARMACY-STORE',
        'name' => 'Pharmacy Store',
        'location_type' => 'PHARMACY',
    ])->assertRedirect(route('inventory.index'));
    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('inv_stock_locations')
        ->where('facility_id', $facilityId)
        ->where('code', 'PHARMACY-STORE')
        ->value('location_type')))->toBe('PHARMACY');
});

it('lets a Tenant Admin create a tenant-wide custom location type only in General', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.location_types.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    TenantContextScope::run($tenantId, null, function () use ($roleId, $organization, $user): void {
        DB::table('idn_roles')->where('id', $roleId)->update([
            'key' => 'tenant_admin',
            'scope_type' => 'ORG',
        ]);
        DB::table('idn_access_assignments')->where('user_id', $user->id)->update([
            'facility_id' => null,
            'organization_id' => $organization->id,
        ]);
    });

    $this->actingAs($user)->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory')
        ->post('/workspaces/inventory/location-types', [
            'code' => 'LABORATORY',
            'name' => 'Laboratory',
        ])
        ->assertRedirect(route('inventory.index'))
        ->assertSessionHas('status', 'Location type saved for this tenant.');

    $type = TenantContextScope::run($tenantId, null, fn () => DB::table('inv_stock_location_types')
        ->where('tenant_id', $tenantId)
        ->where('code', 'LABORATORY')
        ->first());
    expect($type)->not->toBeNull()
        ->and($type->facility_id)->toBeNull();
    $otherFacility = TenantContextScope::run($tenantId, null, fn () => Facility::factory()
        ->forTenant($tenantId, (string) $organization->id)
        ->create());
    $typesAtOtherFacility = queryInv(
        $tenantId,
        (string) $otherFacility->id,
        new GetStockLocationTypes((string) $otherFacility->id),
    );
    expect(collect($typesAtOtherFacility)->pluck('code')->all())->toContain('LABORATORY');
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => null,
        'action' => 'inventory.stock_location_type_defined',
        'target_id' => (string) $type->id,
    ]);
});

it('creates a facility-local location type for a Tenant Admin switched to a facility', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.location_types.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId, 'facility_context_mode' => 'facility'])
        ->from('/workspaces/inventory')
        ->post('/workspaces/inventory/location-types', ['code' => 'LOCAL-LAB', 'name' => 'Local Laboratory'])
        ->assertRedirect(route('inventory.index'));

    $type = TenantContextScope::run($tenantId, null, fn () => DB::table('inv_stock_location_types')
        ->where('tenant_id', $tenantId)
        ->where('code', 'LOCAL-LAB')
        ->first());
    expect($type)->not->toBeNull()
        ->and((string) $type->facility_id)->toBe($facilityId);
});

it('does not allow custom location types to shadow built-ins or duplicate existing tenant codes', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.location_types.manage',
    ]);
    TenantContextScope::run((string) $tenant->id, null, fn () => DB::table('idn_roles')
        ->where('id', $roleId)->update(['key' => 'facility_admin']));
    $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id])
        ->from('/workspaces/inventory')
        ->post('/workspaces/inventory/location-types', ['code' => 'STORE', 'name' => 'Custom Store'])
        ->assertSessionHasErrors('code');
});

it('lets a Tenant Admin define a tenant-wide item with tracking rules in General', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $this->actingAs($user)->withSession([
        'active_facility_id' => $facilityId,
        'facility_context_mode' => 'facility',
    ])
        ->from('/workspaces/inventory')
        ->post('/workspaces/inventory/items', [
            'code' => 'FACILITY-SCOPE-ITEM',
            'name' => 'Should not be created in a facility context',
            'item_type' => 'MEDICATION',
            'uom' => 'TABLET',
        ])
        ->assertForbidden();

    $this->post('/facility-context', ['mode' => 'general']);
    $this->from('/workspaces/inventory')
        ->post('/workspaces/inventory/items', [
            'code' => 'test-paracetamol-500',
            'name' => 'Test Paracetamol 500mg',
            'item_type' => 'MEDICATION',
            'uom' => 'TABLET',
            'track_batch' => true,
            'track_expiry' => true,
        ])
        ->assertRedirect(route('inventory.index'))
        ->assertSessionHas('status', 'Inventory item added to the catalogue.');

    $item = TenantContextScope::run($tenantId, $facilityId, fn () => Item::query()
        ->where('code', 'TEST-PARACETAMOL-500')
        ->firstOrFail());
    expect($item->item_type)->toBe('MEDICATION')
        ->and($item->uom)->toBe('TABLET')
        ->and((bool) $item->track_batch)->toBeTrue()
        ->and((bool) $item->track_expiry)->toBeTrue();

    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'action' => 'inventory.item_defined',
        'target_id' => (string) $item->id,
        'actor_id' => (string) $user->id,
    ]);
});

it('renders General Inventory without implying facility stock or location data', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
        'inventory.location_types.manage',
        'inventory.receipts.create',
    ]);
    $tenantId = (string) $tenant->id;
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);
    seedInventoryItem($tenantId, ['code' => 'GENERAL-ITEM']);
    seedStockLocation($tenantId, (string) $facility->id, ['code' => 'GENERAL-LOCATION']);

    $this->actingAs($user)->withSession(['facility_context_mode' => 'general'])
        ->get('/workspaces/inventory?section=stock-on-hand')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('contextMode', 'general')
            ->where('canManageItems', true)
            ->where('canManageLocations', false)
            ->where('canReceive', false)
            ->has('items', 1)
            ->has('locations', 0)
            ->has('facilityStock', 0)
            ->has('locationTypes', 3));
});

it('lets a Facility Admin create a stock location only for the active facility', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access',
        'inventory.locations.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/inventory')
        ->post('/workspaces/inventory/locations', [
            'code' => 'PHARMACY-STORE',
            'name' => 'Pharmacy Store',
            'location_type' => 'STORE',
        ])
        ->assertRedirect(route('inventory.index'))
        ->assertSessionHas('status', 'Stock location added to this facility.');

    $location = TenantContextScope::run($tenantId, $facilityId, fn () => StockLocation::query()
        ->where('facility_id', $facilityId)
        ->where('code', 'PHARMACY-STORE')
        ->firstOrFail());
    expect($location->location_type)->toBe('STORE');
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'action' => 'inventory.stock_location_defined',
        'target_id' => (string) $location->id,
    ]);
});

it('rejects location types other than Warehouse and Store', function (): void {
    ['facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access',
        'inventory.locations.manage',
    ]);

    $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id])
        ->from('/workspaces/inventory')
        ->post('/workspaces/inventory/locations', [
            'code' => 'LAB-ROOM',
            'name' => 'Laboratory',
            'location_type' => 'LOCATION',
        ])
        ->assertSessionHasErrors('location_type');
});

it('keeps item and location authoring behind their separate permissions', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $facilityId = (string) $facility->id;

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/inventory/items', [
            'code' => 'MED-002',
            'name' => 'Medicine',
            'item_type' => 'MEDICATION',
            'uom' => 'TABLET',
        ])
        ->assertForbidden();

    $this->post('/workspaces/inventory/locations', [
        'code' => 'STORE-2',
        'name' => 'Store 2',
        'location_type' => 'STORE',
    ])->assertForbidden();
});

it('requires batch tracking for expiry-tracked inventory items', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    scopeInventoryUserToOrganization((string) $tenant->id, (string) $organization->id, (string) $user->id, $roleId);

    $this->actingAs($user)->withSession(['facility_context_mode' => 'general'])
        ->post('/workspaces/inventory/items', [
            'code' => 'MED-003',
            'name' => 'Expiry tracked medicine',
            'item_type' => 'MEDICATION',
            'uom' => 'VIAL',
            'track_expiry' => true,
            'track_batch' => false,
        ])
        ->assertSessionHasErrors('track_batch');
});

it('does not expose the retired single-line receipt endpoint', function (): void {
    ['user' => $user] = inventoryFixture();

    $this->actingAs($user)
        ->post('/workspaces/inventory/receipts', [])
        ->assertNotFound();
});

it('returns the movement ledger with a running balance and drift for the selected item and location', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    ['itemId' => $itemId, 'locationId' => $locationId] = receiveStock($tenantId, $facilityId, (string) $user->id, '40');

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get("/workspaces/inventory?section=stock&item_id={$itemId}&location_id={$locationId}&view=ledger")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ledger.itemId', $itemId)
            ->where('ledger.locationId', $locationId)
            ->where('ledger.currentOnHandQty', '40.0000')
            ->where('ledger.ledgerSum', '40.0000')
            ->where('ledger.drift', '0.0000')
            ->has('ledger.entries', 1)
            ->where('ledger.entries.0.movementType', 'RECEIPT')
            ->where('ledger.entries.0.direction', 'IN')
            ->where('ledger.entries.0.quantity', '40.0000')
            ->where('ledger.entries.0.runningBalance', '40.0000')
            ->where('ledger.entries.0.sourceType', 'PROCUREMENT'));
});

it('does not expose a movement ledger without an item and location selection', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $facilityId = (string) $facility->id;

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/inventory?section=stock&view=ledger')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ledger', null));
});

it('lists the facility adjustment worklist and filters it by status', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId, ['code' => 'ADJ-ITEM']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'ADJ-LOC']);

    dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        direction: 'INCREASE',
        quantity: '5',
        reasonCode: 'DATA_ERROR',
        reasonText: 'Count correction',
    ));

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/inventory?section=adjustments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('adjustments', 1)
            ->where('adjustments.0.itemId', (string) $item->id)
            ->where('adjustments.0.locationId', (string) $location->id)
            ->where('adjustments.0.direction', 'INCREASE')
            ->where('adjustments.0.quantity', '5.0000')
            ->where('adjustments.0.reasonCode', 'DATA_ERROR')
            ->where('adjustments.0.reasonText', 'Count correction')
            ->where('adjustments.0.status', 'PENDING_APPROVAL')
            ->where('adjustments.0.resultingMovementId', null)
            ->where('adjustmentStatusFilter', null));

    $this->get('/workspaces/inventory?section=adjustments&adjustment_status=APPROVED')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('adjustments', 0)
            ->where('adjustmentStatusFilter', 'APPROVED'));

    $this->get('/workspaces/inventory?section=adjustments&adjustment_status=NOT_A_STATUS')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('adjustments', 1)
            ->where('adjustmentStatusFilter', null));
});

it('records an authorized adjustment request and lists it as pending', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId, ['code' => 'ADJ-REQ']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'ADJ-REQ-LOC']);
    $adjustmentId = (string) Str::uuid7();
    $payload = [
        'item_id' => (string) $item->id,
        'location_id' => (string) $location->id,
        'direction' => 'DECREASE',
        'quantity' => '3',
        'reason_code' => 'DAMAGED',
        'reason_text' => 'Broken vials',
        'adjustment_id' => $adjustmentId,
        'idempotency_key' => (string) Str::uuid(),
    ];

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/inventory/adjustments', $payload)
        ->assertRedirect(route('inventory.index', ['section' => 'adjustments']))
        ->assertSessionHas('status', 'Stock adjustment requested for approval.');

    // Replaying the same idempotency key must not raise a second adjustment.
    $this->post('/workspaces/inventory/adjustments', $payload)
        ->assertRedirect(route('inventory.index', ['section' => 'adjustments']));

    $adjustments = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->get());
    expect($adjustments)->toHaveCount(1);
    expect($adjustments->first()->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL)
        ->and($adjustments->first()->reason_code)->toBe('DAMAGED')
        ->and((string) $adjustments->first()->quantity)->toBe('3.0000');

    $this->get('/workspaces/inventory?section=adjustments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canRequestAdjustment', true)
            ->has('adjustments', 1)
            ->where('adjustments.0.adjustmentId', $adjustmentId)
            ->where('adjustments.0.direction', 'DECREASE')
            ->where('adjustments.0.status', 'PENDING_APPROVAL'));
});

it('denies adjustment requests without the create permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access',
        'inventory.adjustments.approve',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId);
    $location = seedStockLocation($tenantId, $facilityId);

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/inventory/adjustments', [
            'item_id' => (string) $item->id,
            'location_id' => (string) $location->id,
            'direction' => 'INCREASE',
            'quantity' => '1',
            'reason_code' => 'OTHER',
            'adjustment_id' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertForbidden();
});

it('requires an existing batch number for a batch-tracked adjustment', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId, ['track_batch' => true]);
    $location = seedStockLocation($tenantId, $facilityId);

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/inventory/adjustments', [
            'item_id' => (string) $item->id,
            'location_id' => (string) $location->id,
            'direction' => 'DECREASE',
            'quantity' => '1',
            'reason_code' => 'LOST',
            'adjustment_id' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('batch_number');

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->count()))->toBe(0);
});

it('rejects an unknown adjustment reason code', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId);
    $location = seedStockLocation($tenantId, $facilityId);

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/inventory/adjustments', [
            'item_id' => (string) $item->id,
            'location_id' => (string) $location->id,
            'direction' => 'INCREASE',
            'quantity' => '1',
            'reason_code' => 'NOT_A_REASON',
            'adjustment_id' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('reason_code');
});

it('approves a pending adjustment and posts the compensating movement', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $approver = User::factory()->forTenant($tenantId)->create();
    grantInventoryRole($tenantId, $facilityId, (string) $approver->id, ['inventory.access', 'inventory.adjustments.approve']);

    $item = seedInventoryItem($tenantId, ['code' => 'APPROVE-ITEM']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'APPROVE-LOC']);
    $adjustmentId = (string) Str::uuid7();
    dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: $adjustmentId,
        actorId: (string) $requester->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        direction: 'INCREASE',
        quantity: '5',
        reasonCode: 'DATA_ERROR',
    ));

    $this->actingAs($approver)->withSession(['active_facility_id' => $facilityId])
        ->post("/workspaces/inventory/adjustments/{$adjustmentId}/approve", [
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect(route('inventory.index', ['section' => 'adjustments']))
        ->assertSessionHas('status', 'Stock adjustment approved.');

    $adjustment = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->find($adjustmentId));
    expect($adjustment->status)->toBe(StockAdjustment::STATUS_APPROVED)
        ->and($adjustment->resulting_movement_id)->not->toBeNull();

    $balance = TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()
        ->where('item_id', $item->id)
        ->where('location_id', $location->id)
        ->first());
    expect((string) $balance->on_hand_qty)->toBe('5.0000');
});

it('denies adjustment approval without the approve permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access',
        'inventory.adjustments.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId);
    $location = seedStockLocation($tenantId, $facilityId);
    $adjustmentId = (string) Str::uuid7();
    dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: $adjustmentId,
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        direction: 'INCREASE',
        quantity: '1',
        reasonCode: 'OTHER',
    ));

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->post("/workspaces/inventory/adjustments/{$adjustmentId}/approve", [
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertForbidden();
});

it('does not let the requester approve their own adjustment', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $item = seedInventoryItem($tenantId);
    $location = seedStockLocation($tenantId, $facilityId);
    $adjustmentId = (string) Str::uuid7();
    dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: $adjustmentId,
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        direction: 'INCREASE',
        quantity: '1',
        reasonCode: 'OTHER',
    ));

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->post("/workspaces/inventory/adjustments/{$adjustmentId}/approve", [
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('adjustment');

    $adjustment = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->find($adjustmentId));
    expect($adjustment->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL);
});

it('rejects a pending adjustment with a required decision note', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $approver = User::factory()->forTenant($tenantId)->create();
    grantInventoryRole($tenantId, $facilityId, (string) $approver->id, ['inventory.access', 'inventory.adjustments.approve']);

    $item = seedInventoryItem($tenantId);
    $location = seedStockLocation($tenantId, $facilityId);
    $adjustmentId = (string) Str::uuid7();
    dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: $adjustmentId,
        actorId: (string) $requester->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        direction: 'INCREASE',
        quantity: '1',
        reasonCode: 'OTHER',
    ));

    $this->actingAs($approver)->withSession(['active_facility_id' => $facilityId])
        ->post("/workspaces/inventory/adjustments/{$adjustmentId}/reject", [
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('reason');

    $this->post("/workspaces/inventory/adjustments/{$adjustmentId}/reject", [
        'reason' => 'Count re-verified',
        'idempotency_key' => (string) Str::uuid(),
    ])
        ->assertRedirect(route('inventory.index', ['section' => 'adjustments']))
        ->assertSessionHas('status', 'Stock adjustment rejected.');

    $adjustment = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->find($adjustmentId));
    expect($adjustment->status)->toBe(StockAdjustment::STATUS_REJECTED)
        ->and($adjustment->rejection_reason)->toBe('Count re-verified')
        ->and($adjustment->resulting_movement_id)->toBeNull();
});

it('lists the facility reservation worklist and filters it by status', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $actorId = (string) $user->id;
    $item = seedInventoryItem($tenantId, ['code' => 'RES-ITEM']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'RES-LOC']);
    $holdUntil = now()->addDay()->startOfSecond();

    $createReservation = function (array $attributes) use ($tenantId, $facilityId, $item, $location, $actorId): StockReservation {
        return TenantContextScope::run($tenantId, $facilityId, fn (): StockReservation => StockReservation::factory()
            ->forTenant($tenantId)
            ->create(array_merge([
                'facility_id' => $facilityId,
                'item_id' => (string) $item->id,
                'location_id' => (string) $location->id,
                'requested_by_user_id' => $actorId,
            ], $attributes)));
    };

    $active = $createReservation([
        'reserved_qty' => '12',
        'consumer_type' => 'PHARMACY_ORDER',
        'consumer_ref' => 'RX-1001',
        'hold_until' => $holdUntil,
        'occurred_at' => now(),
        'recorded_at' => now(),
        'status' => StockReservation::STATUS_ACTIVE,
    ]);
    $createReservation([
        'consumer_type' => 'THEATRE_CASE',
        'status' => StockReservation::STATUS_RELEASED,
        'released_reason' => 'Case cancelled',
        'occurred_at' => now()->subMinutes(5),
        'recorded_at' => now()->subMinutes(5),
    ]);

    // A reservation held at another facility never leaks into this worklist.
    $otherFacility = Facility::factory()->forTenant($tenantId, (string) $organization->id)->create();
    TenantContextScope::run($tenantId, (string) $otherFacility->id, fn () => StockReservation::factory()
        ->forTenant($tenantId)
        ->create([
            'facility_id' => (string) $otherFacility->id,
            'item_id' => (string) $item->id,
            'location_id' => (string) $location->id,
            'requested_by_user_id' => $actorId,
        ]));

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/inventory?section=reservations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reservations', 2)
            ->where('reservations.0.reservationId', (string) $active->id)
            ->where('reservations.0.itemId', (string) $item->id)
            ->where('reservations.0.locationId', (string) $location->id)
            ->where('reservations.0.reservedQty', '12.0000')
            ->where('reservations.0.consumerType', 'PHARMACY_ORDER')
            ->where('reservations.0.consumerRef', 'RX-1001')
            ->where('reservations.0.status', 'ACTIVE')
            ->where('reservations.0.holdUntil', $holdUntil->toIso8601String())
            ->where('reservations.1.status', 'RELEASED')
            ->where('reservations.1.releasedReason', 'Case cancelled')
            ->where('reservationStatusFilter', null));

    $this->get('/workspaces/inventory?section=reservations&reservation_status=ACTIVE')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reservations', 1)
            ->where('reservations.0.reservationId', (string) $active->id)
            ->where('reservationStatusFilter', 'ACTIVE'));

    $this->get('/workspaces/inventory?section=reservations&reservation_status=NOT_A_STATUS')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reservations', 2)
            ->where('reservationStatusFilter', null));
});

it('lists facility-wide stock on hand by item, location, and batch with server filters', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = inventoryFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $pharmacy = seedStockLocation($tenantId, $facilityId, ['code' => 'FAC-PHARMACY']);
    $warehouse = seedStockLocation($tenantId, $facilityId, ['code' => 'FAC-WAREHOUSE']);
    $para = seedInventoryItem($tenantId, ['code' => 'FAC-PARA', 'name' => 'Paracetamol']);
    $gloves = seedInventoryItem($tenantId, ['code' => 'FAC-GLOVE', 'name' => 'Gloves']);
    $batch = TenantContextScope::run($tenantId, null, fn () => Batch::factory()
        ->forTenant($tenantId)
        ->forItem((string) $para->id)
        ->create(['batch_number' => 'FAC-B1', 'expiry_date' => '2027-06-30']));

    $createBalance = function (string $facility, string $itemId, string $locationId, string $onHand, ?string $batchId = null, string $reserved = '0') use ($tenantId): void {
        TenantContextScope::run($tenantId, null, fn () => StockBalance::factory()
            ->forTenant($tenantId)
            ->onHand($onHand)
            ->create([
                'facility_id' => $facility,
                'item_id' => $itemId,
                'location_id' => $locationId,
                'batch_id' => $batchId,
                'reserved_qty' => $reserved,
            ]));
    };
    $createBalance($facilityId, (string) $para->id, (string) $pharmacy->id, '100', (string) $batch->id, '20');
    $createBalance($facilityId, (string) $gloves->id, (string) $warehouse->id, '7');

    // A balance at another facility never leaks into this listing.
    $otherFacility = Facility::factory()->forTenant($tenantId, (string) $organization->id)->create();
    $otherLocation = seedStockLocation($tenantId, (string) $otherFacility->id, ['code' => 'OTHER-LOC']);
    $createBalance((string) $otherFacility->id, (string) $gloves->id, (string) $otherLocation->id, '999');

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/inventory?section=stock-on-hand')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facilityStock', 2)
            ->where('facilityStock.0.itemCode', 'FAC-GLOVE')
            ->where('facilityStock.0.locationCode', 'FAC-WAREHOUSE')
            ->where('facilityStock.0.onHand', '7.0000')
            ->where('facilityStock.0.available', '7.0000')
            ->where('facilityStock.1.itemCode', 'FAC-PARA')
            ->where('facilityStock.1.locationCode', 'FAC-PHARMACY')
            ->where('facilityStock.1.batchNumber', 'FAC-B1')
            ->where('facilityStock.1.expiryDate', '2027-06-30')
            ->where('facilityStock.1.onHand', '100.0000')
            ->where('facilityStock.1.reserved', '20.0000')
            ->where('facilityStock.1.available', '80.0000')
            ->where('facilityStockPagination.total', 2)
            ->where('facilityStockPagination.sortBy', 'code')
            ->where('facilityStockPagination.locationId', null));

    $this->get("/workspaces/inventory?section=stock-on-hand&location_id={$pharmacy->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facilityStock', 1)
            ->where('facilityStock.0.itemCode', 'FAC-PARA')
            ->where('facilityStockPagination.locationId', (string) $pharmacy->id));

    $this->get('/workspaces/inventory?section=stock-on-hand&q=GLOVE')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facilityStock', 1)
            ->where('facilityStock.0.itemCode', 'FAC-GLOVE')
            ->where('facilityStockPagination.search', 'GLOVE'));

    $this->get('/workspaces/inventory?section=stock-on-hand&sort_by=on_hand&sort_direction=desc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facilityStock', 2)
            ->where('facilityStock.0.itemCode', 'FAC-PARA')
            ->where('facilityStockPagination.sortBy', 'on_hand')
            ->where('facilityStockPagination.sortDirection', 'desc'));

    // The facility read runs only for the stock-on-hand section.
    $this->get('/workspaces/inventory?section=catalogue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facilityStock', 0)
            ->where('facilityStockPagination.total', 0));
});
