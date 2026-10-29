<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\CreateStockAdjustment;
use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Domain\Exception\ControlledStockWitnessRequired;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Controlled-medicine handling (Phase 8, second slice) — the Phase 0
 * disposition policy's "destruction witness required for controlled drugs".
 *
 * The flag lives on the item master; the witness is enforced by the domain on
 * every DECREASE of a controlled item, and recorded on the adjustment row.
 * Raising stock and moving uncontrolled items are unaffected.
 */
it('records the controlled flag on the item master and returns it to the catalogue', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    $tenantId = (string) $tenant->id;
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $this->withSession(['facility_context_mode' => 'general'])
        ->post('/workspaces/inventory/items', [
            'code' => 'MORPH-10',
            'name' => 'Morphine 10 mg injection',
            'item_type' => 'MEDICATION',
            'uom' => 'VIAL',
            'is_controlled' => true,
        ])
        ->assertSessionHasNoErrors();

    $this->withSession(['facility_context_mode' => 'general'])
        ->post('/workspaces/inventory/items', [
            'code' => 'PARA-500',
            'name' => 'Paracetamol 500 mg tablet',
            'item_type' => 'MEDICATION',
            'uom' => 'TABLET',
        ])
        ->assertSessionHasNoErrors();

    $flags = TenantContextScope::run($tenantId, null, fn () => Item::query()
        ->orderBy('code')
        ->pluck('is_controlled', 'code')
        ->all());

    expect($flags)->toBe(['MORPH-10' => true, 'PARA-500' => false]);

    $this->withSession(['facility_context_mode' => 'general'])
        ->get('/workspaces/inventory?section=catalogue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.code', 'MORPH-10')
            ->where('items.0.isControlled', true)
            ->where('items.1.code', 'PARA-500')
            ->where('items.1.isControlled', false));
});

it('requires a witness to remove controlled stock, and records it on the request', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $this->withSession(['active_facility_id' => $facilityId]);

    $item = seedInventoryItem($tenantId, [
        'code' => 'MORPH-10',
        'name' => 'Morphine 10 mg injection',
        'uom' => 'VIAL',
        'is_controlled' => true,
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'CD-CABINET']);

    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '20',
        sourceType: 'OPENING_BALANCE',
        reason: 'Controlled stock opening balance.',
    ));

    $payload = [
        'item_id' => (string) $item->id,
        'location_id' => (string) $location->id,
        'direction' => 'DECREASE',
        'quantity' => '2',
        'reason_code' => 'EXPIRY',
        'reason_text' => 'Expired ampoules',
    ];

    // No witness → refused before anything is written.
    $this->from('/workspaces/inventory?section=adjustments')
        ->post('/workspaces/inventory/adjustments', $payload + [
            'adjustment_id' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid7(),
        ])
        ->assertSessionHasErrors('witness_name');

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->count()))->toBe(0);

    $adjustmentId = (string) Str::uuid7();

    $this->from('/workspaces/inventory?section=adjustments')
        ->post('/workspaces/inventory/adjustments', $payload + [
            'witness_name' => 'A. Nurse',
            'adjustment_id' => $adjustmentId,
            'idempotency_key' => (string) Str::uuid7(),
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $row = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->findOrFail($adjustmentId));

    expect($row->witness_name)->toBe('A. Nurse')
        ->and($row->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL);

    $this->get('/workspaces/inventory?section=adjustments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('adjustments.0.adjustmentId', $adjustmentId)
            ->where('adjustments.0.witnessName', 'A. Nurse'));
});

it('refuses a witness-less controlled removal at the domain even when the form is bypassed', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $item = seedInventoryItem($tenantId, [
        'code' => 'PETH-50',
        'name' => 'Pethidine 50 mg injection',
        'uom' => 'AMPOULE',
        'is_controlled' => true,
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'CD-CABINET']);

    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '10',
        sourceType: 'OPENING_BALANCE',
        reason: 'Controlled stock opening balance.',
    ));

    $dispatch = fn (?string $witness) => dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        direction: 'DECREASE',
        quantity: '1',
        reasonCode: 'LOST',
        reasonText: null,
        witnessName: $witness,
    ));

    expect(fn () => $dispatch(null))->toThrow(ControlledStockWitnessRequired::class);
    expect(fn () => $dispatch('   '))->toThrow(ControlledStockWitnessRequired::class);

    // A real witness name satisfies the domain.
    $dispatch('Ward Clerk');

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->count()))->toBe(1);
});

it('does not ask for a witness when raising controlled stock or moving an uncontrolled item', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $controlled = seedInventoryItem($tenantId, ['code' => 'MORPH-10', 'is_controlled' => true]);
    $plain = seedInventoryItem($tenantId, ['code' => 'GLOVE-M']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'STORE']);

    // INCREASE on a controlled item, no witness.
    dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $controlled->id,
        locationId: (string) $location->id,
        direction: 'INCREASE',
        quantity: '5',
        reasonCode: 'COUNT_VARIANCE',
        reasonText: 'Found on the shelf',
    ));

    // DECREASE on an uncontrolled item, no witness.
    dispatchInv($tenantId, $facilityId, new CreateStockAdjustment(
        adjustmentId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $plain->id,
        locationId: (string) $location->id,
        direction: 'DECREASE',
        quantity: '3',
        reasonCode: 'DAMAGED',
        reasonText: 'Torn packaging',
    ));

    $rows = TenantContextScope::run($tenantId, $facilityId, fn () => StockAdjustment::query()->get());

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (StockAdjustment $row): bool => $row->witness_name === null))->toBeTrue();
});

it('marks a controlled batch on the expiry worklist', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00'));

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $this->withSession(['active_facility_id' => $facilityId]);

    $item = seedInventoryItem($tenantId, [
        'code' => 'MORPH-10',
        'name' => 'Morphine 10 mg injection',
        'uom' => 'VIAL',
        'track_batch' => true,
        'track_expiry' => true,
        'is_controlled' => true,
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'CD-CABINET']);

    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '6',
        sourceType: 'OPENING_BALANCE',
        batchNumber: 'MORPH-EXP',
        expiryDate: '2026-10-01',
        occurredAt: '2026-09-01',
        reason: 'Backdated controlled opening balance (expiry worklist fixture).',
    ));

    $this->get('/workspaces/inventory?section=expiry')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('expiringStock', 1)
            ->where('expiringStock.0.batchNumber', 'MORPH-EXP')
            ->where('expiringStock.0.controlled', true)
            ->where('expiringStock.0.expired', true));
});
