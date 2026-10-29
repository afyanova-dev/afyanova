<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\ApprovePurchaseRequisition;
use App\Domains\Procurement\Application\Command\CancelPurchaseRequisition;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\Command\RejectPurchaseRequisition;
use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisition;
use App\Domains\Procurement\Contracts\Event\PurchaseRequisitionApproved;
use App\Domains\Procurement\Domain\Exception\ProcurementItemNotUsable;
use App\Domains\Procurement\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\RequisitionLine;
use App\Models\User;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('create → submit → approve: a STOCK line is snapshotted from InventoryApi; a different approver is required; the event is PHI-free', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $approver = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $approver->id, ['procurement.access', 'procurement.requisitions.approve']);

    $item = seedInventoryItem($t, ['code' => 'GLOVE-M', 'name' => 'Gloves M', 'uom' => 'BOX']);

    $id = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: $id, actorId: (string) $requester->id, facilityId: $f,
        lines: [
            ['lineType' => 'STOCK', 'itemRef' => (string) $item->id, 'quantity' => '5'],
            ['lineType' => 'SERVICE', 'description' => 'Analyser calibration', 'quantity' => '1', 'uom' => 'EACH'],
        ],
        reason: 'Ward restock — confidential note',
    ));

    $line = TenantContextScope::run($t, null, fn () => RequisitionLine::query()->where('requisition_id', $id)->orderBy('line_no')->first());
    expect($line->item_code)->toBe('GLOVE-M')->and($line->item_name)->toBe('Gloves M')->and($line->uom)->toBe('BOX');

    dispatchProc($t, $f, new SubmitPurchaseRequisition($id, (string) $requester->id));

    // Self-approval is blocked (§24 / Inv 29).
    expect(fn () => dispatchProc($t, $f, new ApprovePurchaseRequisition($id, (string) $requester->id)))
        ->toThrow(SelfApprovalNotPermitted::class);

    test()->actingAs($approver);
    dispatchProc($t, $f, new ApprovePurchaseRequisition($id, (string) $approver->id));

    $fresh = TenantContextScope::run($t, null, fn () => PurchaseRequisition::query()->find($id));
    expect($fresh->status)->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->and($fresh->reviewed_by_user_id)->toBe((string) $approver->id);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', PurchaseRequisitionApproved::class)->first());
    expect($outbox->payload)->not->toContain('confidential')
        ->and($outbox->payload)->toContain($id);
});

test('a STOCK line referencing an unknown item is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    expect(fn () => dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: (string) Str::uuid7(), actorId: (string) $requester->id, facilityId: $f,
        lines: [['lineType' => 'STOCK', 'itemRef' => (string) Str::uuid7(), 'quantity' => '1']],
    )))->toThrow(ProcurementItemNotUsable::class);
});

test('reject needs a reason and moves to REJECTED; cancel from DRAFT works', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $approver = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $approver->id, ['procurement.access', 'procurement.requisitions.approve']);

    $id = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: $id, actorId: (string) $requester->id, facilityId: $f,
        lines: [['lineType' => 'SERVICE', 'description' => 'X', 'quantity' => '1', 'uom' => 'EACH']],
    ));
    dispatchProc($t, $f, new SubmitPurchaseRequisition($id, (string) $requester->id));

    // A DRAFT requisition can be cancelled by its creator.
    $draftId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: $draftId, actorId: (string) $requester->id, facilityId: $f,
        lines: [['lineType' => 'SERVICE', 'description' => 'Y', 'quantity' => '1', 'uom' => 'EACH']],
    ));
    dispatchProc($t, $f, new CancelPurchaseRequisition($draftId, (string) $requester->id));
    expect(TenantContextScope::run($t, null, fn () => PurchaseRequisition::query()->find($draftId)->status))
        ->toBe(PurchaseRequisition::STATUS_CANCELLED);

    test()->actingAs($approver);
    dispatchProc($t, $f, new RejectPurchaseRequisition($id, (string) $approver->id, 'Out of budget this quarter'));

    $fresh = TenantContextScope::run($t, null, fn () => PurchaseRequisition::query()->find($id));
    expect($fresh->status)->toBe(PurchaseRequisition::STATUS_REJECTED)
        ->and($fresh->rejection_reason)->toBe('Out of budget this quarter');
});

test('marking a requisition emergency without procurement.emergency.manage is refused as NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = procurementFixture([
        'procurement.access', 'procurement.requisitions.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    expect(fn () => dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: (string) Str::uuid7(), actorId: (string) $requester->id, facilityId: $f,
        lines: [['lineType' => 'SERVICE', 'description' => 'Urgent', 'quantity' => '1', 'uom' => 'EACH']],
        emergency: true, emergencyReason: 'Cholera outbreak',
    )))->toThrow(NotFound::class);
});
