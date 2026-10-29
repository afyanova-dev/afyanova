<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\ReserveStock;
use App\Domains\Inventory\Application\Command\TransferStock;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Organization\Domain\Model\Facility;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Stock transfers (Phase 8): moving stock between two locations of ONE
 * facility. The two ledger sides are written together, the facility total does
 * not change, and no second approver is needed. Reserved stock stays put.
 */
function freezeTransferClock(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00'));
}

/** @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} */
function transferFixture(?array $permissions = null): array
{
    freezeTransferClock();

    $fixture = inventoryFixture($permissions ?? [
        'inventory.access',
        'inventory.receipts.create',
        'inventory.transfers.create',
    ]);
    test()->withSession(['active_facility_id' => (string) $fixture['facility']->id]);

    return $fixture;
}

/** @param array<string, mixed> $overrides */
function transferItem(string $tenantId, string $code, array $overrides = []): Item
{
    return seedInventoryItem($tenantId, array_merge([
        'code' => $code,
        'name' => $code.' item',
    ], $overrides));
}

function transferReceive(
    string $tenantId,
    string $facilityId,
    string $actorId,
    Item $item,
    string $locationId,
    string $quantity,
    ?string $batchNumber = null,
    ?string $expiryDate = null,
    ?string $receivedOn = null,
): void {
    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: $actorId,
        itemId: (string) $item->id,
        locationId: $locationId,
        quantity: $quantity,
        sourceType: 'OPENING_BALANCE',
        batchNumber: $batchNumber,
        expiryDate: $expiryDate,
        occurredAt: $receivedOn,
        reason: $receivedOn !== null ? 'Transfer fixture (backdated opening balance).' : null,
    ));
}

/** @param array<string, mixed> $overrides */
function transferPayload(string $itemId, string $fromLocationId, string $toLocationId, string $quantity, array $overrides = []): array
{
    return array_merge([
        'transfer_id' => (string) Str::uuid7(),
        'idempotency_key' => (string) Str::uuid7(),
        'item_id' => $itemId,
        'from_location_id' => $fromLocationId,
        'to_location_id' => $toLocationId,
        'quantity' => $quantity,
    ], $overrides);
}

function transferBalance(string $tenantId, string $facilityId, string $itemId, string $locationId): string
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()
        ->where('item_id', $itemId)
        ->where('location_id', $locationId)
        ->whereNull('batch_id')
        ->value('on_hand_qty') ?? '0.0000');
}

it('moves stock between two locations with one document and two ledger sides', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = transferFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN', 'name' => 'Main Store'])->id;
    $pharmacy = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY', 'name' => 'Pharmacy Counter'])->id;

    $item = transferItem($tenantId, 'PARA-500');
    transferReceive($tenantId, $facilityId, $userId, $item, $main, '100');

    $payload = transferPayload((string) $item->id, $main, $pharmacy, '30', [
        'reason' => 'Weekly refill of the pharmacy counter.',
    ]);

    $this->from('/workspaces/inventory?section=transfers')
        ->post('/workspaces/inventory/transfers', $payload)
        ->assertRedirect('/workspaces/inventory?section=transfers&transfer_from='.$main)
        ->assertSessionHas('status', 'Stock moved.');

    expect(transferBalance($tenantId, $facilityId, (string) $item->id, $main))->toBe('70.0000')
        ->and(transferBalance($tenantId, $facilityId, (string) $item->id, $pharmacy))->toBe('30.0000')
        ->and(inventoryReconciles($tenantId, $facilityId, (string) $item->id, $main))->toBeTrue()
        ->and(inventoryReconciles($tenantId, $facilityId, (string) $item->id, $pharmacy))->toBeTrue();

    $this->assertDatabaseHas('inv_stock_transfers', [
        'id' => $payload['transfer_id'],
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'item_id' => (string) $item->id,
        'from_location_id' => $main,
        'to_location_id' => $pharmacy,
        'quantity' => 30,
        'reason_text' => 'Weekly refill of the pharmacy counter.',
        'moved_by_user_id' => $userId,
    ]);

    $this->assertDatabaseHas('inv_stock_movements', [
        'source_ref' => $payload['transfer_id'],
        'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
        'direction' => StockMovement::DIRECTION_OUT,
        'location_id' => $main,
        'quantity' => 30,
        'source_type' => 'TRANSFER',
    ]);
    $this->assertDatabaseHas('inv_stock_movements', [
        'source_ref' => $payload['transfer_id'],
        'movement_type' => StockMovement::TYPE_TRANSFER_IN,
        'direction' => StockMovement::DIRECTION_IN,
        'location_id' => $pharmacy,
        'quantity' => 30,
        'source_type' => 'TRANSFER',
    ]);
    $this->assertDatabaseCount('inv_stock_movements', 3); // 1 receipt + 2 transfer sides

    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'action' => 'inventory.stock_transferred',
        'target_type' => 'stock_transfer',
        'target_id' => $payload['transfer_id'],
    ]);

    $this->get('/workspaces/inventory?section=transfers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->has('transfers', 1)
            ->where('transfers.0.transferId', $payload['transfer_id'])
            ->where('transfers.0.transferNumber', 'TRF-00000001')
            ->where('transfers.0.itemCode', 'PARA-500')
            ->where('transfers.0.fromLocationCode', 'MAIN')
            ->where('transfers.0.toLocationCode', 'PHARMACY')
            ->where('transfers.0.quantity', '30.0000')
            ->where('transfers.0.batchNumber', null)
            ->where('transfers.0.reasonText', 'Weekly refill of the pharmacy counter.')
            ->where('canTransfer', true));

    // The source list the move panel uses: the remainder sits in MAIN, the
    // moved stock now sits in PHARMACY.
    $this->get('/workspaces/inventory?section=transfers&transfer_from='.$main)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transferFromLocationId', $main)
            ->has('transferSource', 1)
            ->where('transferSource.0.itemCode', 'PARA-500')
            ->where('transferSource.0.available', '70.0000'));

    $this->get('/workspaces/inventory?section=transfers&transfer_from='.$pharmacy)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transferFromLocationId', $pharmacy)
            ->has('transferSource', 1)
            ->where('transferSource.0.available', '30.0000'));
});

it('keeps the facility total unchanged', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = transferFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $ward = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'WARD'])->id;
    $item = transferItem($tenantId, 'GLV-1', ['uom' => 'PAIR']);

    transferReceive($tenantId, $facilityId, (string) $user->id, $item, $main, '12');

    dispatchInv($tenantId, $facilityId, new TransferStock(
        transferId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        fromLocationId: $main,
        toLocationId: $ward,
        quantity: '5',
    ));

    $total = TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()
        ->where('item_id', (string) $item->id)
        ->sum('on_hand_qty'));

    expect((string) $total)->toBe('12.0000');
});

it('refuses to move more than the source location has', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = transferFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $pharmacy = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY'])->id;
    $item = transferItem($tenantId, 'AMOX-500');
    transferReceive($tenantId, $facilityId, (string) $user->id, $item, $main, '10');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $pharmacy, '11'))
        ->assertSessionHasErrors('transfer');

    expect(transferBalance($tenantId, $facilityId, (string) $item->id, $main))->toBe('10.0000')
        ->and(transferBalance($tenantId, $facilityId, (string) $item->id, $pharmacy))->toBe('0.0000');
    $this->assertDatabaseCount('inv_stock_transfers', 0);
});

it('does not move stock that is reserved', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = transferFixture([
        'inventory.access',
        'inventory.receipts.create',
        'inventory.transfers.create',
        'inventory.reservations.manage',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $pharmacy = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY'])->id;
    $item = transferItem($tenantId, 'RES-1');
    transferReceive($tenantId, $facilityId, $userId, $item, $main, '100');

    dispatchInv($tenantId, $facilityId, new ReserveStock(
        reservationId: (string) Str::uuid7(),
        actorId: $userId,
        itemId: (string) $item->id,
        locationId: $main,
        quantity: '40',
        consumerType: 'PHARMACY_ORDER',
    ));

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $pharmacy, '61'))
        ->assertSessionHasErrors('transfer');

    dispatchInv($tenantId, $facilityId, new TransferStock(
        transferId: (string) Str::uuid7(),
        actorId: $userId,
        itemId: (string) $item->id,
        fromLocationId: $main,
        toLocationId: $pharmacy,
        quantity: '60',
    ));

    expect(transferBalance($tenantId, $facilityId, (string) $item->id, $main))->toBe('40.0000')
        ->and(transferBalance($tenantId, $facilityId, (string) $item->id, $pharmacy))->toBe('60.0000');
});

it('refuses the same location and a cross-facility move', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = transferFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $otherFacility = TenantContextScope::run($tenantId, null, fn () => Facility::factory()
        ->forTenant($tenantId, (string) $organization->id)
        ->create());
    $elsewhere = (string) seedStockLocation($tenantId, (string) $otherFacility->id, ['code' => 'OTHER'])->id;

    $item = transferItem($tenantId, 'CROSS-1');
    transferReceive($tenantId, $facilityId, (string) $user->id, $item, $main, '10');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $main, '1'))
        ->assertSessionHasErrors('to_location_id');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $elsewhere, '1'))
        ->assertSessionHasErrors('transfer');

    expect(transferBalance($tenantId, $facilityId, (string) $item->id, $main))->toBe('10.0000');
    $this->assertDatabaseCount('inv_stock_transfers', 0);
});

it('moves a named batch and refuses an unknown or expired one', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = transferFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $pharmacy = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY'])->id;
    $item = transferItem($tenantId, 'BATCH-1', ['track_batch' => true, 'track_expiry' => true]);

    transferReceive($tenantId, $facilityId, $userId, $item, $main, '20', 'B-FRESH', '2027-06-30', '2026-09-01');
    transferReceive($tenantId, $facilityId, $userId, $item, $main, '5', 'B-OLD', '2026-10-01', '2026-09-01');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $pharmacy, '2'))
        ->assertSessionHasErrors('batch_number');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $pharmacy, '2', [
        'batch_number' => 'NO-SUCH-BATCH',
    ]))->assertSessionHasErrors('transfer');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $pharmacy, '2', [
        'batch_number' => 'B-OLD',
    ]))->assertSessionHasErrors('transfer');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $pharmacy, '8', [
        'batch_number' => 'B-FRESH',
    ]))->assertSessionHas('status', 'Stock moved.');

    $this->assertDatabaseHas('inv_stock_transfers', [
        'item_id' => (string) $item->id,
        'from_location_id' => $main,
        'to_location_id' => $pharmacy,
        'quantity' => 8,
    ]);
    $this->assertDatabaseHas('inv_stock_balances', [
        'item_id' => (string) $item->id,
        'location_id' => $main,
        'on_hand_qty' => 12,
    ]);
    $this->assertDatabaseHas('inv_stock_balances', [
        'item_id' => (string) $item->id,
        'location_id' => $pharmacy,
        'on_hand_qty' => 8,
    ]);

    // The move panel lists the batches that remain at the source, with the
    // expiry eligibility the server computed — not a second opinion in React.
    $this->get('/workspaces/inventory?section=transfers&transfer_from='.$main)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transferSource', 1)
            ->has('transferSource.0.batches', 2)
            ->where('transferSource.0.batches.0.batchNumber', 'B-FRESH')
            ->where('transferSource.0.batches.0.eligible', true)
            ->where('transferSource.0.batches.1.batchNumber', 'B-OLD')
            ->where('transferSource.0.batches.1.eligible', false));
});

it('requires the transfer permission', function (): void {
    ['tenant' => $tenant] = transferFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;

    $item = transferItem($tenantId, 'NOPERM-1');

    $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, (string) Str::uuid7(), (string) Str::uuid7(), '1'))
        ->assertForbidden();

    $this->assertDatabaseCount('inv_stock_transfers', 0);
});

it('validates the quantity and requires the transfer ids', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = transferFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $pharmacy = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY'])->id;
    $item = transferItem($tenantId, 'VALID-1');

    foreach (['0', '-1', '1.23456', 'not-a-number'] as $quantity) {
        $this->post('/workspaces/inventory/transfers', transferPayload((string) $item->id, $main, $pharmacy, $quantity))
            ->assertSessionHasErrors('quantity');
    }

    $missing = transferPayload((string) $item->id, $main, $pharmacy, '1');
    unset($missing['transfer_id'], $missing['idempotency_key']);

    $this->post('/workspaces/inventory/transfers', $missing)
        ->assertSessionHasErrors(['transfer_id', 'idempotency_key']);

    $this->assertDatabaseCount('inv_stock_transfers', 0);
});

it('lists only the current facility transfers', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = transferFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $main = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $pharmacy = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY'])->id;
    $item = transferItem($tenantId, 'MINE-1');
    transferReceive($tenantId, $facilityId, (string) $user->id, $item, $main, '10');

    dispatchInv($tenantId, $facilityId, new TransferStock(
        transferId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        fromLocationId: $main,
        toLocationId: $pharmacy,
        quantity: '1',
    ));

    $this->get('/workspaces/inventory?section=transfers')
        ->assertInertia(fn (Assert $page) => $page
            ->has('transfers', 1)
            ->where('transfers.0.itemCode', 'MINE-1'));
});

it('shows the general-context note instead of a facility transfer list', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = transferFixture();

    scopeInventoryUserToOrganization((string) $tenant->id, (string) $organization->id, (string) $user->id, $roleId);

    $this->withSession(['active_facility_id' => null, 'facility_context_mode' => 'general'])
        ->get('/workspaces/inventory?section=transfers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transfers', 0)
            ->where('transferFromLocationId', null)
            ->has('transferSource', 0)
            ->where('canTransfer', false));
});
