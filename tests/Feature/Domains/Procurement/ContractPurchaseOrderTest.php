<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\ActivateContract;
use App\Domains\Procurement\Application\Command\CreateContract;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Query\GetContract;
use App\Domains\Procurement\Application\Query\GetPurchaseOrder;
use App\Domains\Procurement\Domain\Exception\ContractLineInvalid;
use App\Domains\Procurement\Domain\Exception\ContractNotUsable;
use App\Domains\Procurement\Domain\Exception\ContractSupplierMismatch;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, buyer: User, supplier: Supplier}
 */
function contractPoActors(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $buyer] = procurementFixture([
        'procurement.access', 'procurement.contracts.manage', 'procurement.orders.create',
    ]);
    $t = (string) $tenant->id;

    return [
        't' => $t,
        'f' => (string) $facility->id,
        'buyer' => $buyer,
        'supplier' => seedSupplier($t, ['status' => Supplier::STATUS_ACTIVE]),
    ];
}

test('CreatePurchaseOrder(contractId) seeds lines at the agreed contract price; sourcing_method = CONTRACT', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = contractPoActors();
    $itemId = (string) seedInventoryItem($t)->id;

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'STOCK', 'itemRef' => $itemId, 'uom' => 'BOX', 'agreedUnitPrice' => '1500', 'quantityCeiling' => '500'],
        ['lineType' => 'SERVICE', 'description' => 'Install', 'uom' => 'EACH', 'agreedUnitPrice' => '90000'],
    ]);

    test()->actingAs($buyer);
    $poId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [
            ['contractLineId' => $lineIds[0], 'quantity' => '100'],
            ['contractLineId' => $lineIds[1], 'quantity' => '1'],
        ],
        contractId: $contractId,
    ));

    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect($po->sourcing_method)->toBe(PurchaseOrder::SOURCING_METHOD_CONTRACT)
        ->and((string) $po->contract_id)->toBe($contractId)
        ->and($po->sourcing_rfq_id)->toBeNull();

    $lines = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->orderBy('line_no')->get());
    expect($lines)->toHaveCount(2)
        ->and((string) $lines[0]->unit_price)->toBe('1500.00')
        ->and((string) $lines[0]->ordered_qty)->toBe('100.0000')
        ->and($lines[0]->item_ref)->toBe($itemId)
        ->and((string) $lines[1]->unit_price)->toBe('90000.00')
        ->and((string) $lines[1]->line_type)->toBe('SERVICE');

    // The reader surfaces the link + the contract's poCount ticks up.
    $summary = queryProc($t, $f, new GetPurchaseOrder($poId));
    expect($summary->contractId)->toBe($contractId);
    expect(queryProc($t, $f, new GetContract($contractId))->poCount)->toBe(1);
});

test('a quantity above the line ceiling is refused (Inv 33 — per-PO)', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = contractPoActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Cover', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '10'],
    ]);

    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['contractLineId' => $lineIds[0], 'quantity' => '11']],
        contractId: $contractId,
    )))->toThrow(ContractLineInvalid::class);
});

test('a PO line citing a contract line that is not on the contract is refused', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = contractPoActors();
    [$contractId] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id);

    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['contractLineId' => (string) Str::uuid7(), 'quantity' => '1']],
        contractId: $contractId,
    )))->toThrow(ContractLineInvalid::class);
});

test('a PO naming a different supplier from the contract is refused', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = contractPoActors();
    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id);
    $otherSupplier = seedSupplier($t, ['status' => Supplier::STATUS_ACTIVE, 'code' => 'OTHER']);

    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $otherSupplier->id,
        lines: [['contractLineId' => $lineIds[0], 'quantity' => '1']],
        contractId: $contractId,
    )))->toThrow(ContractSupplierMismatch::class);
});

test('a DRAFT contract cannot price a PO (check-at-use)', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = contractPoActors();

    $contractId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreateContract(
        contractId: $contractId, actorId: (string) $buyer->id, supplierId: (string) $supplier->id,
        title: 'x', effectiveDate: '2026-01-01', expiryDate: '2030-01-01',
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'uom' => 'EACH', 'agreedUnitPrice' => '10']],
    ));
    $lineId = (string) TenantContextScope::run($t, null, fn () => ContractLine::query()
        ->where('contract_id', $contractId)->value('id'));

    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['contractLineId' => $lineId, 'quantity' => '1']],
        contractId: $contractId,
    )))->toThrow(ContractNotUsable::class);
});

test('an expired (past expiry_date) ACTIVE contract cannot price a PO (Inv 32, check-at-use)', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = contractPoActors();

    $contractId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreateContract(
        contractId: $contractId, actorId: (string) $buyer->id, supplierId: (string) $supplier->id,
        title: 'x', effectiveDate: '2020-01-01', expiryDate: '2021-01-01',
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'uom' => 'EACH', 'agreedUnitPrice' => '10']],
    ));
    $lineId = (string) TenantContextScope::run($t, null, fn () => ContractLine::query()
        ->where('contract_id', $contractId)->value('id'));

    $activator = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $activator->id, ['procurement.access', 'procurement.contracts.manage']);
    test()->actingAs($activator);
    dispatchProc($t, $f, new ActivateContract($contractId, (string) $activator->id));

    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['contractLineId' => $lineId, 'quantity' => '1']],
        contractId: $contractId,
    )))->toThrow(ContractNotUsable::class);
});

test('rfqId and contractId are mutually exclusive; a contract line needs a contractLineId', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = contractPoActors();

    expect(fn () => new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [], rfqId: (string) Str::uuid7(), contractId: (string) Str::uuid7(),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['quantity' => '1']], contractId: (string) Str::uuid7(),
    ))->toThrow(InvalidArgumentException::class);
});
