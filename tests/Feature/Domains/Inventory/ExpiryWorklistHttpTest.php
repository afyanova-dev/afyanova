<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Expiry worklist (Phase 8, first slice) — the Phase 0 disposition policy made
 * visible: a 90-day near-expiry window, expired stock already blocked from
 * dispensing, and removal only through the existing two-person adjustment.
 *
 * The worklist is a read; these tests also prove the whole removal loop through
 * the real routes, because that is the part that must never become a balance
 * edit.
 */

/** Freeze "today" so day arithmetic is exact. */
function freezeExpiryClock(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00'));
}

/** @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} */
function expiryFixture(?array $permissions = null): array
{
    freezeExpiryClock();
    $fixture = inventoryFixture($permissions);
    test()->withSession(['active_facility_id' => (string) $fixture['facility']->id]);

    return $fixture;
}

function receiveBatch(
    string $tenantId,
    string $facilityId,
    string $actorId,
    string $itemId,
    string $locationId,
    string $batchNumber,
    string $quantity,
    string $expiryDate,
    string $receivedOn,
): void {
    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: $actorId,
        itemId: $itemId,
        locationId: $locationId,
        quantity: $quantity,
        sourceType: 'OPENING_BALANCE',
        batchNumber: $batchNumber,
        expiryDate: $expiryDate,
        occurredAt: $receivedOn,
        reason: 'Expiry worklist fixture (backdated opening balance).',
    ));
}

it('lists expired and near-expiry batches, soonest first, with exact day counts', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = expiryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $item = seedInventoryItem($tenantId, [
        'code' => 'AMOX-500',
        'name' => 'Amoxicillin 500 mg',
        'uom' => 'CAPSULE',
        'track_batch' => true,
        'track_expiry' => true,
    ]);
    $location = seedStockLocation($tenantId, $facilityId, [
        'code' => 'PHARMACY',
        'name' => 'Pharmacy Store',
    ]);

    receiveBatch($tenantId, $facilityId, $userId, (string) $item->id, (string) $location->id, 'EXP-1', '10', '2026-10-01', '2026-09-01');
    receiveBatch($tenantId, $facilityId, $userId, (string) $item->id, (string) $location->id, 'TODAY-1', '5', '2026-10-19', '2026-09-20');
    receiveBatch($tenantId, $facilityId, $userId, (string) $item->id, (string) $location->id, 'SOON-1', '7', '2026-10-31', '2026-10-01');
    receiveBatch($tenantId, $facilityId, $userId, (string) $item->id, (string) $location->id, 'FAR-1', '9', '2027-02-01', '2026-10-01');

    $this->get('/workspaces/inventory?section=expiry')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->where('expiryWindowDays', 90)
            ->has('expiringStock', 3)
            ->where('expiringStock.0.batchNumber', 'EXP-1')
            ->where('expiringStock.0.itemCode', 'AMOX-500')
            ->where('expiringStock.0.locationCode', 'PHARMACY')
            ->where('expiringStock.0.onHand', '10.0000')
            ->where('expiringStock.0.uom', 'CAPSULE')
            ->where('expiringStock.0.expiryDate', '2026-10-01')
            ->where('expiringStock.0.daysRemaining', -18)
            ->where('expiringStock.0.expired', true)
            // A batch is usable through the END of its expiry date.
            ->where('expiringStock.1.batchNumber', 'TODAY-1')
            ->where('expiringStock.1.daysRemaining', 0)
            ->where('expiringStock.1.expired', false)
            ->where('expiringStock.2.batchNumber', 'SOON-1')
            ->where('expiringStock.2.daysRemaining', 12)
            ->where('expiringStock.2.expired', false));
});

it('includes the 90th day, and excludes later batches, non-expiry items and other facilities', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = expiryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $expiringItem = seedInventoryItem($tenantId, [
        'code' => 'ARTE-20',
        'name' => 'Artemether 20 mg',
        'uom' => 'TABLET',
        'track_batch' => true,
        'track_expiry' => true,
    ]);
    $plainItem = seedInventoryItem($tenantId, [
        'code' => 'GLOVE-M',
        'name' => 'Examination gloves',
        'uom' => 'PAIR',
        'track_batch' => false,
        'track_expiry' => false,
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'MAIN-WH']);

    receiveBatch($tenantId, $facilityId, $userId, (string) $expiringItem->id, (string) $location->id, 'DAY-90', '4', '2027-01-17', '2026-10-01');
    receiveBatch($tenantId, $facilityId, $userId, (string) $expiringItem->id, (string) $location->id, 'DAY-91', '4', '2027-01-18', '2026-10-01');
    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: $userId,
        itemId: (string) $plainItem->id,
        locationId: (string) $location->id,
        quantity: '20',
        sourceType: 'OPENING_BALANCE',
        reason: 'No expiry tracking.',
    ));

    // A different facility in the same tenant must not leak into this worklist.
    $otherFacility = TenantContextScope::run($tenantId, null, fn () => Facility::factory()
        ->forTenant($tenantId, (string) $organization->id)
        ->create(['name' => 'Second Facility', 'code' => 'SECOND']));
    $otherLocation = seedStockLocation($tenantId, (string) $otherFacility->id, ['code' => 'OTHER-WH']);
    grantInventoryRole($tenantId, (string) $otherFacility->id, $userId, [
        'inventory.access',
        'inventory.receipts.create',
    ]);
    receiveBatch($tenantId, (string) $otherFacility->id, $userId, (string) $expiringItem->id, (string) $otherLocation->id, 'OTHER-1', '3', '2026-11-01', '2026-10-01');

    $this->get('/workspaces/inventory?section=expiry')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('expiringStock', 1)
            ->where('expiringStock.0.batchNumber', 'DAY-90')
            ->where('expiringStock.0.daysRemaining', 90)
            ->where('expiringStock.0.expired', false));
});

it('requires inventory access to read the worklist and the create key to request a removal', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = expiryFixture([
        'inventory.access',
        'inventory.receipts.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $item = seedInventoryItem($tenantId, [
        'code' => 'INS-100',
        'name' => 'Insulin 100 IU',
        'uom' => 'VIAL',
        'track_batch' => true,
        'track_expiry' => true,
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'COLD']);
    receiveBatch($tenantId, $facilityId, $userId, (string) $item->id, (string) $location->id, 'INS-1', '6', '2026-10-05', '2026-09-01');

    // Readable, but the screen may not offer the removal action.
    $this->get('/workspaces/inventory?section=expiry')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canRequestAdjustment', false)
            ->has('expiringStock', 1));

    $this->post('/workspaces/inventory/adjustments', [
        'item_id' => (string) $item->id,
        'location_id' => (string) $location->id,
        'direction' => 'DECREASE',
        'quantity' => '6',
        'reason_code' => 'EXPIRY',
        'reason_text' => 'Expired batch',
        'batch_number' => 'INS-1',
        'adjustment_id' => (string) Str::uuid7(),
        'idempotency_key' => (string) Str::uuid7(),
    ])->assertForbidden();
});

it('refuses the inventory page without inventory access', function (): void {
    expiryFixture(['inventory.receipts.create']);

    $this->get('/workspaces/inventory?section=expiry')->assertForbidden();
});

it('removes an expired batch only after a second person approves the request', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = expiryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $item = seedInventoryItem($tenantId, [
        'code' => 'CEF-1G',
        'name' => 'Ceftriaxone 1 g',
        'uom' => 'VIAL',
        'track_batch' => true,
        'track_expiry' => true,
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY']);
    receiveBatch($tenantId, $facilityId, $userId, (string) $item->id, (string) $location->id, 'CEF-EXP', '10', '2026-10-02', '2026-09-01');

    $adjustmentId = (string) Str::uuid7();

    $this->post('/workspaces/inventory/adjustments', [
        'item_id' => (string) $item->id,
        'location_id' => (string) $location->id,
        'direction' => 'DECREASE',
        'quantity' => '10',
        'reason_code' => 'EXPIRY',
        'reason_text' => 'Expired; destroyed and witnessed.',
        'batch_number' => 'CEF-EXP',
        'adjustment_id' => $adjustmentId,
        'idempotency_key' => (string) Str::uuid7(),
    ])->assertRedirect()->assertSessionHas('status');

    $adjustment = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => StockAdjustment::query()->findOrFail($adjustmentId),
    );

    expect($adjustment->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL)
        ->and($adjustment->direction)->toBe(StockAdjustment::DIRECTION_DECREASE)
        ->and($adjustment->reason_code)->toBe('EXPIRY')
        ->and($adjustment->reason_text)->toBe('Expired; destroyed and witnessed.')
        ->and((string) $adjustment->batch_id)->not->toBe('')
        ->and((string) $adjustment->quantity)->toBe('10.0000');

    // Stock has NOT moved yet, so the worklist still shows the batch.
    $this->get('/workspaces/inventory?section=expiry')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('expiringStock', 1)
            ->where('adjustments.0.status', StockAdjustment::STATUS_PENDING_APPROVAL));

    // Whoever raised the request cannot approve it.
    $this->post('/workspaces/inventory/adjustments/'.$adjustmentId.'/approve', [
        'idempotency_key' => (string) Str::uuid7(),
    ])->assertRedirect()->assertSessionHasErrors('adjustment');

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => StockAdjustment::query()->findOrFail($adjustmentId)->status,
    ))->toBe(StockAdjustment::STATUS_PENDING_APPROVAL);

    $second = User::factory()->forTenant($tenantId)->create();
    grantInventoryRole($tenantId, $facilityId, (string) $second->id, [
        'inventory.access',
        'inventory.adjustments.approve',
    ]);

    $this->actingAs($second)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/inventory/adjustments/'.$adjustmentId.'/approve', [
            'idempotency_key' => (string) Str::uuid7(),
        ])
        ->assertRedirect()->assertSessionHas('status');

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => StockAdjustment::query()->findOrFail($adjustmentId)->status,
    ))->toBe(StockAdjustment::STATUS_APPROVED);

    $onHand = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => StockBalance::query()
            ->where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->value('on_hand_qty'),
    );

    expect((string) $onHand)->toBe('0.0000')
        ->and(inventoryReconciles($tenantId, $facilityId, (string) $item->id, (string) $location->id, (string) $adjustment->batch_id))->toBeTrue();

    $movement = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => StockMovement::query()
            ->where('source_type', 'ADJUSTMENT')
            ->where('source_ref', $adjustmentId)
            ->firstOrFail(),
    );

    expect($movement->movement_type)->toBe(StockMovement::TYPE_ADJUSTMENT)
        ->and($movement->direction)->toBe(StockMovement::DIRECTION_OUT)
        ->and((string) $movement->quantity)->toBe('10.0000')
        ->and((string) $movement->batch_id)->toBe((string) $adjustment->batch_id);

    // An emptied batch leaves the worklist.
    $this->get('/workspaces/inventory?section=expiry')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('expiringStock', 0));

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => Batch::query()->where('batch_number', 'CEF-EXP')->exists(),
    ))->toBeTrue();
});
