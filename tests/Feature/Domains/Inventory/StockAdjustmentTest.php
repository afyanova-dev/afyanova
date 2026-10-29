<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ApproveStockAdjustment;
use App\Domains\Inventory\Application\Command\CreateStockAdjustment;
use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\RejectStockAdjustment;
use App\Domains\Inventory\Contracts\Event\StockAdjusted;
use App\Domains\Inventory\Domain\Exception\IllegalStockAdjustmentTransition;
use App\Domains\Inventory\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('a DECREASE adjustment: create → approve posts the compensating movement, updates the balance, keeps the ledger reconciled, emits StockAdjusted', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $approver = User::factory()->forTenant($t)->create();
    grantInventoryRole($t, $f, (string) $approver->id, ['inventory.access', 'inventory.adjustments.approve']);

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: (string) $requester->id,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '50',
    ));

    $adjustmentId = (string) Str::uuid7();
    dispatchInv($t, $f, new CreateStockAdjustment(
        adjustmentId: $adjustmentId, actorId: (string) $requester->id,
        itemId: (string) $item->id, locationId: (string) $location->id,
        direction: 'DECREASE', quantity: '8', reasonCode: 'damaged', reasonText: 'crushed in transit',
    ));

    $pending = TenantContextScope::run($t, $f, fn () => StockAdjustment::query()->find($adjustmentId));
    expect($pending->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL);

    test()->actingAs($approver);
    dispatchInv($t, $f, new ApproveStockAdjustment(adjustmentId: $adjustmentId, actorId: (string) $approver->id));

    $approved = TenantContextScope::run($t, $f, fn () => StockAdjustment::query()->find($adjustmentId));
    expect($approved->status)->toBe(StockAdjustment::STATUS_APPROVED)
        ->and($approved->approved_by_user_id)->toBe((string) $approver->id)
        ->and($approved->resulting_movement_id)->not->toBeNull();

    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()->where('item_id', $item->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('42.0000');

    $movement = TenantContextScope::run($t, $f, fn () => StockMovement::query()
        ->where('movement_type', StockMovement::TYPE_ADJUSTMENT)->first());
    expect($movement->source_type)->toBe('ADJUSTMENT')
        ->and($movement->source_ref)->toBe($adjustmentId);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', StockAdjusted::class)->first());
    expect($outbox->payload)->toContain('DAMAGED')->and($outbox->payload)->not->toContain('crushed in transit');

    expect(inventoryReconciles($t, $f, (string) $item->id, (string) $location->id))->toBeTrue();
});

test('the requester cannot approve their own adjustment (§224 / Inv 12)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '20',
    ));

    $adjustmentId = (string) Str::uuid7();
    dispatchInv($t, $f, new CreateStockAdjustment(
        adjustmentId: $adjustmentId, actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id,
        direction: 'DECREASE', quantity: '2', reasonCode: 'LOST',
    ));

    expect(fn () => dispatchInv($t, $f, new ApproveStockAdjustment(adjustmentId: $adjustmentId, actorId: $a)))
        ->toThrow(SelfApprovalNotPermitted::class);
});

test('an already-rejected adjustment cannot be approved', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $approver = User::factory()->forTenant($t)->create();
    grantInventoryRole($t, $f, (string) $approver->id, ['inventory.access', 'inventory.adjustments.approve']);

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    $adjustmentId = (string) Str::uuid7();
    dispatchInv($t, $f, new CreateStockAdjustment(
        adjustmentId: $adjustmentId, actorId: (string) $requester->id,
        itemId: (string) $item->id, locationId: (string) $location->id,
        direction: 'INCREASE', quantity: '5', reasonCode: 'DATA_ERROR',
    ));

    test()->actingAs($approver);
    dispatchInv($t, $f, new RejectStockAdjustment(adjustmentId: $adjustmentId, actorId: (string) $approver->id, reason: 'count re-verified'));

    expect(fn () => dispatchInv($t, $f, new ApproveStockAdjustment(adjustmentId: $adjustmentId, actorId: (string) $approver->id)))
        ->toThrow(IllegalStockAdjustmentTransition::class);
});

test('approving requires inventory.adjustments.approve', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(permissions: ['inventory.access', 'inventory.adjustments.create']);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    $adjustmentId = (string) Str::uuid7();
    dispatchInv($t, $f, new CreateStockAdjustment(
        adjustmentId: $adjustmentId, actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id,
        direction: 'INCREASE', quantity: '1', reasonCode: 'OTHER',
    ));

    expect(fn () => dispatchInv($t, $f, new ApproveStockAdjustment(adjustmentId: $adjustmentId, actorId: $a)))
        ->toThrow(AuthorizationException::class);
});
