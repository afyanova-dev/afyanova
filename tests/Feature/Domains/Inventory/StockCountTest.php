<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ApproveStockAdjustment;
use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Domain\Exception\InsufficientStock;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockCount;
use App\Domains\Inventory\Domain\Model\StockCountLine;
use App\Models\User;
use App\Shared\Http\Middleware\HandleInertiaRequests;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Stock counts (Phase 8): a physical count at ONE location. Starting a count
 * snapshots what the system believes is on the shelf; the counter writes down
 * what they find; the sheet shows the difference. A count NEVER edits a stock
 * balance — a variance is corrected afterwards through the two-person stock
 * adjustment.
 */
function freezeCountClock(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
}

/** @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} */
function stockCountFixture(?array $permissions = null): array
{
    freezeCountClock();

    $fixture = inventoryFixture($permissions ?? [
        'inventory.access',
        'inventory.receipts.create',
        'inventory.counts.manage',
    ]);
    test()->withSession(['active_facility_id' => (string) $fixture['facility']->id]);

    return $fixture;
}

/** @param array<string, mixed> $overrides */
function countItem(string $tenantId, string $code, array $overrides = []): Item
{
    return seedInventoryItem($tenantId, array_merge([
        'code' => $code,
        'name' => $code.' item',
    ], $overrides));
}

function countReceive(
    string $tenantId,
    string $facilityId,
    string $actorId,
    Item $item,
    string $locationId,
    string $quantity,
    ?string $batchNumber = null,
): void {
    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: $actorId,
        itemId: (string) $item->id,
        locationId: $locationId,
        quantity: $quantity,
        sourceType: 'OPENING_BALANCE',
        batchNumber: $batchNumber,
    ));
}

/** @param array<string, mixed> $overrides */
function countStartPayload(string $locationId, array $overrides = []): array
{
    return array_merge([
        'stock_count_id' => (string) Str::uuid7(),
        'idempotency_key' => (string) Str::uuid7(),
        'location_id' => $locationId,
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function countSheetPayload(array $counts, array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid7(),
        'counts' => $counts,
    ], $overrides);
}

function countOnHand(string $tenantId, string $facilityId, string $itemId, string $locationId): string
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()
        ->where('item_id', $itemId)
        ->where('location_id', $locationId)
        ->whereNull('batch_id')
        ->value('on_hand_qty') ?? '0.0000');
}

/** @return array<string, StockCountLine> keyed by item id */
function countLines(string $tenantId, string $facilityId, string $countId): array
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => StockCountLine::query()
        ->where('stock_count_id', $countId)
        ->get()
        ->keyBy('item_id')
        ->all());
}

it('starts a count and snapshots what the location holds without touching stock', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN', 'name' => 'Main Store'])->id;
    $pharmacy = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY', 'name' => 'Pharmacy Counter'])->id;

    $para = countItem($tenantId, 'PARA-500');
    $gloves = countItem($tenantId, 'GLV-1', ['uom' => 'PAIR']);

    countReceive($tenantId, $facilityId, $userId, $para, $store, '100');
    countReceive($tenantId, $facilityId, $userId, $gloves, $store, '12');
    // Stock at another location must not appear on this sheet.
    countReceive($tenantId, $facilityId, $userId, $para, $pharmacy, '40');

    $payload = countStartPayload($store, ['notes' => 'Monthly count.']);

    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', $payload)
        ->assertRedirect('/workspaces/inventory?section=counts&count_id='.$payload['stock_count_id'])
        ->assertSessionHas('status', 'Stock count started.');

    $this->assertDatabaseHas('inv_stock_counts', [
        'id' => $payload['stock_count_id'],
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'location_id' => $store,
        'count_number' => 'CNT-00000001',
        'status' => StockCount::STATUS_IN_PROGRESS,
        'notes' => 'Monthly count.',
        'started_by_user_id' => $userId,
    ]);

    $this->assertDatabaseHas('inv_stock_count_lines', [
        'stock_count_id' => $payload['stock_count_id'],
        'item_id' => (string) $para->id,
        'batch_id' => null,
        'system_qty' => 100,
        'counted_qty' => null,
    ]);
    $this->assertDatabaseHas('inv_stock_count_lines', [
        'stock_count_id' => $payload['stock_count_id'],
        'item_id' => (string) $gloves->id,
        'system_qty' => 12,
        'counted_qty' => null,
    ]);
    $this->assertDatabaseCount('inv_stock_count_lines', 2);

    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'action' => 'inventory.stock_count_started',
        'target_type' => 'stock_count',
        'target_id' => $payload['stock_count_id'],
    ]);

    // The count is only a record: the shelf quantities are untouched and the
    // ledger still reconciles.
    expect(countOnHand($tenantId, $facilityId, (string) $para->id, $store))->toBe('100.0000')
        ->and(inventoryReconciles($tenantId, $facilityId, (string) $para->id, $store))->toBeTrue();

    $this->get('/workspaces/inventory?section=counts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->has('stockCounts', 1)
            ->where('stockCounts.0.countId', $payload['stock_count_id'])
            ->where('stockCounts.0.countNumber', 'CNT-00000001')
            ->where('stockCounts.0.locationCode', 'MAIN')
            ->where('stockCounts.0.status', StockCount::STATUS_IN_PROGRESS)
            ->where('stockCounts.0.lineCount', 2)
            ->where('stockCounts.0.countedCount', 0)
            ->where('stockCounts.0.varianceCount', 0)
            ->where('canManageCounts', true)
            ->where('stockCountSheet', null));
});

it('serves a count-sheet partial reload without building unrelated Inventory props', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $item = countItem($tenantId, 'PARTIAL-COUNT');
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '8');
    $start = countStartPayload($store);
    $countId = (string) $start['stock_count_id'];
    $this->post('/workspaces/inventory/stock-counts', $start)->assertRedirect();

    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Inventory/Index',
        'X-Inertia-Partial-Data' => 'stockCountSheet',
    ];
    $version = app(HandleInertiaRequests::class)->version(request());
    if ($version !== null) {
        $headers['X-Inertia-Version'] = $version;
    }

    $this->withHeaders($headers)->get('/workspaces/inventory?section=counts&count_id='.$countId)
        ->assertOk()
        ->assertJsonPath('props.stockCountSheet.count.countId', $countId)
        ->assertJsonMissingPath('props.items')
        ->assertJsonMissingPath('props.adjustments');
});

it('records counted quantities and shows the variance against the snapshot', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $para = countItem($tenantId, 'PARA-500');
    $gloves = countItem($tenantId, 'GLV-1', ['uom' => 'PAIR']);
    countReceive($tenantId, $facilityId, $userId, $para, $store, '100');
    countReceive($tenantId, $facilityId, $userId, $gloves, $store, '12');

    $payload = countStartPayload($store);
    $countId = (string) $payload['stock_count_id'];
    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', $payload)
        ->assertRedirect('/workspaces/inventory?section=counts&count_id='.$countId);

    $lines = countLines($tenantId, $facilityId, $countId);
    $paraLine = (string) $lines[(string) $para->id]->id;
    $glovesLine = (string) $lines[(string) $gloves->id]->id;

    // 4 capsules short of the record, and the gloves shelf is empty — zero is a
    // real count, not "not counted".
    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/counts', countSheetPayload([
            $paraLine => '96',
            $glovesLine => '0',
        ]))
        ->assertRedirect('/workspaces/inventory?section=counts&count_id='.$countId)
        ->assertSessionHas('status', 'Counts saved.');

    $this->assertDatabaseHas('inv_stock_count_lines', [
        'id' => $paraLine,
        'counted_qty' => 96,
        'counted_by_user_id' => $userId,
    ]);
    $this->assertDatabaseHas('inv_stock_count_lines', [
        'id' => $glovesLine,
        'counted_qty' => 0,
    ]);

    // Ordering follows the item code: GLV-1 before PARA-500.
    $this->get('/workspaces/inventory?section=counts&count_id='.$countId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->where('stockCountSheet.count.countId', $countId)
            ->where('stockCountSheet.count.countedCount', 2)
            ->where('stockCountSheet.count.varianceCount', 2)
            ->has('stockCountSheet.lines', 2)
            ->where('stockCountSheet.lines.0.itemCode', 'GLV-1')
            ->where('stockCountSheet.lines.0.systemQty', '12.0000')
            ->where('stockCountSheet.lines.0.countedQty', '0.0000')
            ->where('stockCountSheet.lines.0.variance', '-12.0000')
            ->where('stockCountSheet.lines.1.itemCode', 'PARA-500')
            ->where('stockCountSheet.lines.1.systemQty', '100.0000')
            ->where('stockCountSheet.lines.1.countedQty', '96.0000')
            ->where('stockCountSheet.lines.1.variance', '-4.0000'));

    // Clearing a line returns it to "not counted": no false variance, and no
    // false shortage.
    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/counts', countSheetPayload([
            $glovesLine => null,
        ]))
        ->assertRedirect('/workspaces/inventory?section=counts&count_id='.$countId);

    $this->assertDatabaseHas('inv_stock_count_lines', [
        'id' => $glovesLine,
        'counted_qty' => null,
        'counted_by_user_id' => null,
    ]);

    $this->get('/workspaces/inventory?section=counts&count_id='.$countId)
        ->assertInertia(fn (Assert $page) => $page
            ->where('stockCountSheet.count.countedCount', 1)
            ->where('stockCountSheet.count.varianceCount', 1)
            ->where('stockCountSheet.lines.0.countedQty', null)
            ->where('stockCountSheet.lines.0.variance', null));

    // Still no stock movement: counting is not correcting.
    expect(countOnHand($tenantId, $facilityId, (string) $para->id, $store))->toBe('100.0000');
});

it('allows only one open count per location', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $item = countItem($tenantId, 'PARA-500');
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '100');

    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', countStartPayload($store))
        ->assertSessionHas('status', 'Stock count started.');

    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', countStartPayload($store))
        ->assertSessionHasErrors('stock_count');

    $this->assertDatabaseCount('inv_stock_counts', 1);
});

it('puts an item the system does not list onto the sheet', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $storeTwo = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'STORE-2'])->id;
    $para = countItem($tenantId, 'PARA-500');
    $gloves = countItem($tenantId, 'GLV-1', ['uom' => 'PAIR']);
    $bandage = countItem($tenantId, 'BAND-1', ['track_batch' => true]);
    countReceive($tenantId, $facilityId, $userId, $para, $store, '100');
    // The batch exists because it was received somewhere in the facility.
    countReceive($tenantId, $facilityId, $userId, $bandage, $storeTwo, '5', 'B1');

    $payload = countStartPayload($store);
    $countId = (string) $payload['stock_count_id'];
    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', $payload)
        ->assertRedirect('/workspaces/inventory?section=counts&count_id='.$countId);

    // A find the system did not know about: the snapshot is the current on-hand (0).
    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/lines', [
            'idempotency_key' => (string) Str::uuid7(),
            'item_id' => (string) $gloves->id,
        ])
        ->assertRedirect('/workspaces/inventory?section=counts&count_id='.$countId)
        ->assertSessionHas('status', 'Item added to the count.');

    $this->assertDatabaseHas('inv_stock_count_lines', [
        'stock_count_id' => $countId,
        'item_id' => (string) $gloves->id,
        'system_qty' => 0,
        'counted_qty' => null,
    ]);

    // The same item cannot sit on the sheet twice.
    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/lines', [
            'idempotency_key' => (string) Str::uuid7(),
            'item_id' => (string) $gloves->id,
        ])
        ->assertSessionHasErrors('stock_count');
    $this->assertDatabaseCount('inv_stock_count_lines', 2);

    // A batch-tracked item must name its batch, and the batch must exist.
    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/lines', [
            'idempotency_key' => (string) Str::uuid7(),
            'item_id' => (string) $bandage->id,
        ])
        ->assertSessionHasErrors('stock_count');

    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/lines', [
            'idempotency_key' => (string) Str::uuid7(),
            'item_id' => (string) $bandage->id,
            'batch_number' => 'NOPE',
        ])
        ->assertSessionHasErrors('stock_count');

    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/lines', [
            'idempotency_key' => (string) Str::uuid7(),
            'item_id' => (string) $bandage->id,
            'batch_number' => 'B1',
        ])
        ->assertRedirect('/workspaces/inventory?section=counts&count_id='.$countId);

    $this->assertDatabaseHas('inv_stock_count_lines', [
        'stock_count_id' => $countId,
        'item_id' => (string) $bandage->id,
        'system_qty' => 0,
    ]);
    $this->assertDatabaseCount('inv_stock_count_lines', 3);
});

it('completes with pending variance adjustments and rechecks stock when a second person approves', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture([
        'inventory.access',
        'inventory.receipts.create',
        'inventory.counts.manage',
        'inventory.adjustments.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $item = countItem($tenantId, 'COUNT-APPROVAL');
    $surplusItem = countItem($tenantId, 'COUNT-SURPLUS');
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '100');
    countReceive($tenantId, $facilityId, (string) $user->id, $surplusItem, $store, '20');

    $start = countStartPayload($store);
    $countId = (string) $start['stock_count_id'];
    $this->post('/workspaces/inventory/stock-counts', $start)->assertRedirect();
    $lines = countLines($tenantId, $facilityId, $countId);
    $lineId = (string) $lines[(string) $item->id]->id;
    $surplusLineId = (string) $lines[(string) $surplusItem->id]->id;

    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/close', [
            'idempotency_key' => (string) Str::uuid7(),
            'decision' => StockCount::COMPLETION_KEEP,
            'counts' => [$lineId => '90', $surplusLineId => '25'],
        ])
        ->assertRedirect('/workspaces/inventory?section=counts')
        ->assertSessionHas('status', 'Stock count completed — 2 corrections sent for approval.');

    $line = TenantContextScope::run($tenantId, $facilityId, fn () => StockCountLine::query()->findOrFail($lineId));
    $surplusLine = TenantContextScope::run($tenantId, $facilityId, fn () => StockCountLine::query()->findOrFail($surplusLineId));
    $adjustment = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->findOrFail($line->variance_adjustment_id));
    $surplusAdjustment = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->findOrFail($surplusLine->variance_adjustment_id));
    expect($line->counted_qty)->toBe('90.0000')
        ->and($line->counted_by_user_id)->toBe((string) $user->id)
        ->and($adjustment->reason_code)->toBe('COUNT_VARIANCE')
        ->and($adjustment->direction)->toBe(StockAdjustment::DIRECTION_DECREASE)
        ->and($adjustment->quantity)->toBe('10.0000')
        ->and($adjustment->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL)
        ->and($adjustment->requested_by_user_id)->toBe((string) $user->id)
        ->and($surplusLine->counted_qty)->toBe('25.0000')
        ->and($surplusAdjustment->reason_code)->toBe('COUNT_VARIANCE')
        ->and($surplusAdjustment->direction)->toBe(StockAdjustment::DIRECTION_INCREASE)
        ->and($surplusAdjustment->quantity)->toBe('5.0000')
        ->and(TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->count()))->toBe(2)
        ->and(countOnHand($tenantId, $facilityId, (string) $item->id, $store))->toBe('100.0000');

    $approver = User::factory()->forTenant($tenantId)->create();
    grantInventoryRole($tenantId, $facilityId, (string) $approver->id, [
        'inventory.access',
        'inventory.adjustments.approve',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()
        ->where('item_id', $item->id)
        ->where('location_id', $store)
        ->update(['on_hand_qty' => '5.0000']));

    test()->actingAs($approver);
    expect(fn () => dispatchInv($tenantId, $facilityId, new ApproveStockAdjustment(
        adjustmentId: (string) $adjustment->id,
        actorId: (string) $approver->id,
    )))->toThrow(InsufficientStock::class);

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()
        ->findOrFail($adjustment->id)->status))->toBe(StockAdjustment::STATUS_PENDING_APPROVAL)
        ->and(countOnHand($tenantId, $facilityId, (string) $item->id, $store))->toBe('5.0000');
});

it('skips a variance when stock moved after the count snapshot', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture([
        'inventory.access',
        'inventory.receipts.create',
        'inventory.counts.manage',
        'inventory.adjustments.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $item = countItem($tenantId, 'COUNT-STALE');
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '100');

    $start = countStartPayload($store);
    $countId = (string) $start['stock_count_id'];
    $this->post('/workspaces/inventory/stock-counts', $start)->assertRedirect();
    $lineId = (string) countLines($tenantId, $facilityId, $countId)[(string) $item->id]->id;
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '5');

    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/close', [
            'idempotency_key' => (string) Str::uuid7(),
            'decision' => StockCount::COMPLETION_KEEP,
            'counts' => [$lineId => '90'],
        ])
        ->assertRedirect('/workspaces/inventory?section=counts')
        ->assertSessionHas('status', 'Stock count completed — 1 difference was not corrected automatically.');

    $line = TenantContextScope::run($tenantId, $facilityId, fn () => StockCountLine::query()->findOrFail($lineId));
    expect($line->variance_adjustment_id)->toBeNull()
        ->and($line->variance_skipped_reason)->toBe(StockCountLine::SKIPPED_STOCK_MOVED)
        ->and(TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->count()))->toBe(0);
});

it('requires a witness before completing a controlled-stock shortfall', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture([
        'inventory.access',
        'inventory.receipts.create',
        'inventory.counts.manage',
        'inventory.adjustments.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $item = countItem($tenantId, 'COUNT-CONTROLLED', ['is_controlled' => true]);
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '10');

    $start = countStartPayload($store);
    $countId = (string) $start['stock_count_id'];
    $this->post('/workspaces/inventory/stock-counts', $start)->assertRedirect();
    $lineId = (string) countLines($tenantId, $facilityId, $countId)[(string) $item->id]->id;

    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/close', [
            'idempotency_key' => (string) Str::uuid7(),
            'decision' => StockCount::COMPLETION_KEEP,
            'counts' => [$lineId => '5'],
        ])
        ->assertSessionHasErrors('stock_count');
    $this->assertDatabaseHas('inv_stock_counts', ['id' => $countId, 'status' => StockCount::STATUS_IN_PROGRESS]);
    $this->assertDatabaseHas('inv_stock_count_lines', ['id' => $lineId, 'counted_qty' => null]);

    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/close', [
            'idempotency_key' => (string) Str::uuid7(),
            'decision' => StockCount::COMPLETION_KEEP,
            'counts' => [$lineId => '5'],
            'witness_name' => 'Jordan Witness',
        ])
        ->assertSessionHas('status', 'Stock count completed — 1 correction sent for approval.');

    $line = TenantContextScope::run($tenantId, $facilityId, fn () => StockCountLine::query()->findOrFail($lineId));
    $adjustment = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->findOrFail($line->variance_adjustment_id));
    expect($adjustment->witness_name)->toBe('Jordan Witness');
});

it('completes or discards a count and freezes a closed sheet', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $item = countItem($tenantId, 'PARA-500');
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '100');

    $first = countStartPayload($store);
    $firstId = (string) $first['stock_count_id'];
    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', $first);

    $lines = countLines($tenantId, $facilityId, $firstId);
    $lineId = (string) $lines[(string) $item->id]->id;

    $this->from('/workspaces/inventory?section=counts&count_id='.$firstId)
        ->post('/workspaces/inventory/stock-counts/'.$firstId.'/close', [
            'idempotency_key' => (string) Str::uuid7(),
            'decision' => StockCount::COMPLETION_KEEP,
        ])
        ->assertRedirect('/workspaces/inventory?section=counts')
        ->assertSessionHas('status', 'Stock count completed. No differences were found.');

    $this->assertDatabaseHas('inv_stock_counts', [
        'id' => $firstId,
        'status' => StockCount::STATUS_COMPLETED,
        'closed_at' => '2026-10-20 09:00:00',
    ]);
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'action' => 'inventory.stock_count_completed',
        'target_id' => $firstId,
    ]);

    // A closed sheet can no longer be counted.
    $this->from('/workspaces/inventory?section=counts&count_id='.$firstId)
        ->post('/workspaces/inventory/stock-counts/'.$firstId.'/counts', countSheetPayload([
            $lineId => '90',
        ]))
        ->assertSessionHasErrors('stock_count');
    $this->assertDatabaseHas('inv_stock_count_lines', ['id' => $lineId, 'counted_qty' => null]);

    // Closing frees the location for the next count.
    $second = countStartPayload($store);
    $secondId = (string) $second['stock_count_id'];
    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', $second)
        ->assertSessionHas('status', 'Stock count started.');

    $this->from('/workspaces/inventory?section=counts&count_id='.$secondId)
        ->post('/workspaces/inventory/stock-counts/'.$secondId.'/close', [
            'idempotency_key' => (string) Str::uuid7(),
            'decision' => StockCount::COMPLETION_DISCARD,
        ])
        ->assertSessionHas('status', 'Stock count discarded.');

    $this->assertDatabaseHas('inv_stock_counts', [
        'id' => $secondId,
        'status' => StockCount::STATUS_DISCARDED,
    ]);
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'action' => 'inventory.stock_count_discarded',
        'target_id' => $secondId,
    ]);
});

it('refuses counts without the count permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility] = stockCountFixture([
        'inventory.access',
        'inventory.receipts.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;

    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', countStartPayload($store))
        ->assertForbidden();

    $this->assertDatabaseCount('inv_stock_counts', 0);

    $this->get('/workspaces/inventory?section=counts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stockCounts', 0)
            ->where('canManageCounts', false));
});

it('rejects a negative counted quantity and an unknown location', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = stockCountFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $store = (string) seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN'])->id;
    $item = countItem($tenantId, 'PARA-500');
    countReceive($tenantId, $facilityId, (string) $user->id, $item, $store, '100');

    $payload = countStartPayload($store);
    $countId = (string) $payload['stock_count_id'];
    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', $payload);

    $lineId = (string) countLines($tenantId, $facilityId, $countId)[(string) $item->id]->id;

    $this->from('/workspaces/inventory?section=counts&count_id='.$countId)
        ->post('/workspaces/inventory/stock-counts/'.$countId.'/counts', countSheetPayload([
            $lineId => '-1',
        ]))
        ->assertSessionHasErrors('counts.'.$lineId);

    $this->assertDatabaseHas('inv_stock_count_lines', ['id' => $lineId, 'counted_qty' => null]);

    $this->from('/workspaces/inventory?section=counts')
        ->post('/workspaces/inventory/stock-counts', countStartPayload((string) Str::uuid7()))
        ->assertSessionHasErrors('stock_count');
});
