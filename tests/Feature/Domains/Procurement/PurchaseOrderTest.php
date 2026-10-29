<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AmendPurchaseOrder;
use App\Domains\Procurement\Application\Command\ApprovePurchaseOrder;
use App\Domains\Procurement\Application\Command\CancelPurchaseOrder;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\Command\IssuePurchaseOrder;
use App\Domains\Procurement\Application\Command\SubmitPurchaseOrder;
use App\Domains\Procurement\Contracts\Event\PurchaseOrderIssued;
use App\Domains\Procurement\Domain\Exception\DuplicateDirectPurchaseOrder;
use App\Domains\Procurement\Domain\Exception\IllegalPurchaseOrderTransition;
use App\Domains\Procurement\Domain\Exception\RequisitionNotApprovedForOrder;
use App\Domains\Procurement\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Procurement\Domain\Exception\SelfIssuanceNotPermitted;
use App\Domains\Procurement\Domain\Exception\SupplierNotOrderable;
use App\Domains\Procurement\Domain\Model\PoAmendment;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, creator: User, approver: User, issuer: User, supplier: Supplier}
 */
function poActors(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access', 'procurement.orders.create', 'procurement.requisitions.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $approver = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $approver->id, ['procurement.access', 'procurement.orders.approve']);
    $issuer = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $issuer->id, ['procurement.access', 'procurement.orders.issue', 'procurement.orders.amend']);

    $supplier = seedSupplier($t, ['status' => Supplier::STATUS_ACTIVE]);

    return ['t' => $t, 'f' => $f, 'creator' => $creator, 'approver' => $approver, 'issuer' => $issuer, 'supplier' => $supplier];
}

test('DRAFT → SUBMITTED → APPROVED → ISSUED with different actors; the issued event is PHI-free', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'approver' => $approver, 'issuer' => $issuer, 'supplier' => $supplier] = poActors();

    // The creator + approver are ALSO authorised to approve / issue — the SoD
    // guard, not the permission gate, is what must block acting on one's own PO.
    grantProcurementRole($t, $f, (string) $creator->id, ['procurement.orders.approve', 'procurement.orders.issue']);
    grantProcurementRole($t, $f, (string) $approver->id, ['procurement.orders.issue']);

    $poId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $creator->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['lineType' => 'SERVICE', 'description' => 'Annual analyser cover', 'quantity' => '1', 'uom' => 'EACH', 'unitPrice' => '4200000']],
        notes: 'Internal budget line 42 — sensitive',
    ));

    dispatchProc($t, $f, new SubmitPurchaseOrder($poId, (string) $creator->id));

    expect(fn () => dispatchProc($t, $f, new ApprovePurchaseOrder($poId, (string) $creator->id)))
        ->toThrow(SelfApprovalNotPermitted::class);

    test()->actingAs($approver);
    dispatchProc($t, $f, new ApprovePurchaseOrder($poId, (string) $approver->id));

    // The approver may not also issue (§228 different buyer).
    expect(fn () => dispatchProc($t, $f, new IssuePurchaseOrder($poId, (string) $approver->id)))
        ->toThrow(SelfIssuanceNotPermitted::class);

    test()->actingAs($issuer);
    dispatchProc($t, $f, new IssuePurchaseOrder($poId, (string) $issuer->id));

    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect($po->status)->toBe(PurchaseOrder::STATUS_ISSUED)
        ->and($po->approved_by_user_id)->toBe((string) $approver->id)
        ->and($po->issued_by_user_id)->toBe((string) $issuer->id);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', PurchaseOrderIssued::class)->first());
    expect($outbox->payload)->not->toContain('sensitive')
        ->and($outbox->payload)->toContain($poId);
});

test('a PO citing a non-APPROVED requisition is rejected; a non-ACTIVE supplier blocks creation', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'approver' => $approver, 'supplier' => $supplier] = poActors();

    // Non-approved requisition (DRAFT) → RequisitionNotApprovedForOrder.
    $reqId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: $reqId, actorId: (string) $creator->id, facilityId: $f,
        lines: [['lineType' => 'SERVICE', 'description' => 'x', 'quantity' => '1', 'uom' => 'EACH']],
    ));

    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $creator->id, facilityId: $f,
        supplierId: (string) $supplier->id, requisitionId: $reqId,
        lines: [['lineType' => 'SERVICE', 'description' => 'x', 'quantity' => '1', 'uom' => 'EACH', 'unitPrice' => '100']],
    )))->toThrow(RequisitionNotApprovedForOrder::class);

    // Suspended supplier → SupplierNotOrderable.
    $suspended = seedSupplier($t, ['status' => Supplier::STATUS_SUSPENDED]);
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $creator->id, facilityId: $f,
        supplierId: (string) $suspended->id,
        lines: [['lineType' => 'SERVICE', 'description' => 'x', 'quantity' => '1', 'uom' => 'EACH', 'unitPrice' => '100']],
    )))->toThrow(SupplierNotOrderable::class);
});

test('an exact direct-order repeat requires a reason and records the override', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier] = poActors();
    $lines = [[
        'lineType' => 'SERVICE',
        'description' => 'Annual analyser cover',
        'quantity' => '1',
        'uom' => 'EACH',
        'unitPrice' => '4200000',
    ]];
    $originalId = (string) Str::uuid7();

    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $originalId,
        actorId: (string) $creator->id,
        facilityId: $f,
        supplierId: (string) $supplier->id,
        lines: $lines,
        costCentreRef: 'LAB',
        budgetRef: 'FY26-LAB',
    ));

    $original = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($originalId));
    expect($original->cost_centre_ref)->toBe('LAB')
        ->and($original->budget_ref)->toBe('FY26-LAB');

    $blockedId = (string) Str::uuid7();
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $blockedId,
        actorId: (string) $creator->id,
        facilityId: $f,
        supplierId: (string) $supplier->id,
        lines: $lines,
    )))->toThrow(DuplicateDirectPurchaseOrder::class);

    $overrideId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $overrideId,
        actorId: (string) $creator->id,
        facilityId: $f,
        supplierId: (string) $supplier->id,
        lines: $lines,
        duplicateOverrideReason: 'Replacement order after stock loss',
    ));

    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($blockedId)))->toBeNull()
        ->and(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($overrideId)))->not->toBeNull();

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.order_duplicate_overridden')
        ->where('target_id', $overrideId)
        ->first());
    expect($audit->reason)->toBe('Replacement order after stock loss')
        ->and(json_decode((string) $audit->metadata, true)['duplicate_of_purchase_order_id'])->toBe($originalId);
});

test('AmendPurchaseOrder is legal ONLY on ISSUED, appends an append-only row, bumps amendment_count + version', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'approver' => $approver, 'issuer' => $issuer, 'supplier' => $supplier] = poActors();

    $poId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $creator->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['lineType' => 'STOCK', 'itemRef' => (string) seedInventoryItem($t)->id, 'quantity' => '10', 'uom' => 'BOX', 'unitPrice' => '1000']],
    ));
    dispatchProc($t, $f, new SubmitPurchaseOrder($poId, (string) $creator->id));

    $lineId = (string) TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->first()->id);

    // Not amendable before ISSUED.
    test()->actingAs($issuer);
    expect(fn () => dispatchProc($t, $f, new AmendPurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $issuer->id, amendmentType: PoAmendment::TYPE_QUANTITY,
        reason: 'early', newValue: '12', poLineId: $lineId,
    )))->toThrow(IllegalPurchaseOrderTransition::class);

    test()->actingAs($approver);
    dispatchProc($t, $f, new ApprovePurchaseOrder($poId, (string) $approver->id));
    test()->actingAs($issuer);
    dispatchProc($t, $f, new IssuePurchaseOrder($poId, (string) $issuer->id));

    dispatchProc($t, $f, new AmendPurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $issuer->id, amendmentType: PoAmendment::TYPE_QUANTITY,
        reason: 'Ward demand rose', newValue: '15', poLineId: $lineId,
    ));

    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect((int) $po->amendment_count)->toBe(1)->and((int) $po->version)->toBeGreaterThan(0)
        ->and($po->status)->toBe(PurchaseOrder::STATUS_ISSUED);

    $amendment = TenantContextScope::run($t, null, fn () => PoAmendment::query()->where('po_id', $poId)->first());
    expect($amendment->sequence)->toBe(1)
        ->and($amendment->field_changed)->toBe('ordered_qty')
        ->and($amendment->previous_value)->toBe('10.0000')
        ->and($amendment->new_value)->toBe('15')
        ->and($amendment->reason)->toBe('Ward demand rose');

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->find($lineId));
    expect((string) $line->ordered_qty)->toBe('15.0000');

    // pcr_po_amendments is append-only at the DB — UPDATE / DELETE affect 0 rows.
    $policies = DB::table('pg_policies')->where('tablename', 'pcr_po_amendments')->pluck('policyname')->all();
    expect($policies)->toContain('pcr_po_amendments_no_update')
        ->and($policies)->toContain('pcr_po_amendments_no_delete');

    $raw = DB::connection('pgsql_raw');
    $raw->transaction(function () use ($raw, $t, $poId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$t]);
        expect($raw->table('pcr_po_amendments')->where('po_id', $poId)->update(['reason' => 'x']))->toBe(0);
        expect($raw->table('pcr_po_amendments')->where('po_id', $poId)->delete())->toBe(0);
    });
});

test('cancel an issued PO with a mandatory reason', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'approver' => $approver, 'issuer' => $issuer, 'supplier' => $supplier] = poActors();

    $poId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $creator->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['lineType' => 'SERVICE', 'description' => 'x', 'quantity' => '1', 'uom' => 'EACH', 'unitPrice' => '100']],
    ));
    dispatchProc($t, $f, new SubmitPurchaseOrder($poId, (string) $creator->id));
    test()->actingAs($approver);
    dispatchProc($t, $f, new ApprovePurchaseOrder($poId, (string) $approver->id));
    test()->actingAs($issuer);
    dispatchProc($t, $f, new IssuePurchaseOrder($poId, (string) $issuer->id));
    dispatchProc($t, $f, new CancelPurchaseOrder($poId, (string) $issuer->id, 'Supplier cannot deliver'));

    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect($po->status)->toBe(PurchaseOrder::STATUS_CANCELLED)
        ->and($po->cancellation_reason)->toBe('Supplier cannot deliver');
});
