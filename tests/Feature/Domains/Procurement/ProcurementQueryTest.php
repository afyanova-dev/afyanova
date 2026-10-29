<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisition;
use App\Domains\Procurement\Application\Query\GetOpenPurchaseOrders;
use App\Domains\Procurement\Application\Query\GetPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetRequisitionApprovalQueue;
use App\Domains\Procurement\Application\Query\GetSupplier;
use App\Domains\Procurement\Application\Query\GetSuppliers;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Contracts\Dto\SupplierSummary;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('GetSupplier / GetSuppliers return DTOs; GetSuppliers filters by status + category', function () {
    ['tenant' => $tenant, 'facility' => $facility] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $a = seedSupplier($t, ['code' => 'A', 'status' => Supplier::STATUS_ACTIVE, 'categories' => ['PHARMA']]);
    seedSupplier($t, ['code' => 'B', 'status' => Supplier::STATUS_SUSPENDED, 'categories' => ['LAB']]);

    $one = queryProc($t, $f, new GetSupplier((string) $a->id));
    expect($one)->toBeInstanceOf(SupplierSummary::class)->and($one->code)->toBe('A');

    $active = queryProc($t, $f, new GetSuppliers(status: 'ACTIVE'));
    expect($active)->toHaveCount(1)->and($active[0]->code)->toBe('A');

    $pharma = queryProc($t, $f, new GetSuppliers(category: 'PHARMA'));
    expect($pharma)->toHaveCount(1)->and($pharma[0]->code)->toBe('A');
});

test('GetPurchaseOrder is refused for an actor not scoped to the PO facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access', 'procurement.orders.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $supplier = seedSupplier($t);

    $poId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $creator->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['lineType' => 'SERVICE', 'description' => 'x', 'quantity' => '1', 'uom' => 'EACH', 'unitPrice' => '100']],
    ));

    // The creator, scoped to $f, can read it.
    $summary = queryProc($t, $f, new GetPurchaseOrder($poId));
    expect($summary)->toBeInstanceOf(PurchaseOrderSummary::class)
        ->and($summary->orderTotal)->toBe('100.00');

    // A different actor scoped only to another facility cannot.
    $outsider = User::factory()->forTenant($t)->create();
    $otherFacility = (string) Str::uuid7();
    grantProcurementRole($t, $otherFacility, (string) $outsider->id, ['procurement.access']);
    test()->actingAs($outsider);

    expect(fn () => queryProc($t, $otherFacility, new GetPurchaseOrder($poId)))
        ->toThrow(NotFound::class);
});

test('GetRequisitionApprovalQueue lists only SUBMITTED requisitions at the facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $requester] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $approver = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $approver->id, ['procurement.access', 'procurement.requisitions.approve']);

    // one SUBMITTED, one still DRAFT
    $submitted = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: $submitted, actorId: (string) $requester->id, facilityId: $f,
        lines: [['lineType' => 'SERVICE', 'description' => 'x', 'quantity' => '1', 'uom' => 'EACH']],
    ));
    dispatchProc($t, $f, new SubmitPurchaseRequisition($submitted, (string) $requester->id));

    dispatchProc($t, $f, new CreatePurchaseRequisition(
        requisitionId: (string) Str::uuid7(), actorId: (string) $requester->id, facilityId: $f,
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'quantity' => '1', 'uom' => 'EACH']],
    ));

    test()->actingAs($approver);
    $queue = queryProc($t, $f, new GetRequisitionApprovalQueue($f));
    expect($queue)->toHaveCount(1)->and($queue[0]->requisitionId)->toBe($submitted);

    expect(queryProc($t, $f, new GetOpenPurchaseOrders($f)))->toHaveCount(0);
});
