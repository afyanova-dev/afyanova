<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AmendPurchaseOrder;
use App\Domains\Procurement\Application\Command\CancelPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetContract;
use App\Domains\Procurement\Domain\Exception\ContractCeilingExceeded;
use App\Domains\Procurement\Domain\Exception\ContractPriceIsAuthoritative;
use App\Domains\Procurement\Domain\Model\PoAmendment;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, buyer: User, supplier: Supplier}
 */
function consumptionActors(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $buyer] = procurementFixture([
        'procurement.access', 'procurement.contracts.manage', 'procurement.orders.create',
        'inventory.access', 'inventory.receipts.create',
    ]);
    $t = (string) $tenant->id;

    return [
        't' => $t,
        'f' => (string) $facility->id,
        'buyer' => $buyer,
        'supplier' => seedSupplier($t, ['status' => Supplier::STATUS_ACTIVE]),
    ];
}

test('the cumulative ceiling (INV-33a) is enforced across multiple POs on create', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = consumptionActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);

    createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '60'],
    ]);
    createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '30'],
    ]);

    // committed is now 90; +20 would be 110 > 100.
    expect(fn () => createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '20'],
    ]))->toThrow(ContractCeilingExceeded::class);

    // The rejected PO left no rows (transaction rolled back).
    expect(queryProc($t, $f, new GetContract($contractId))->lines[0]->committed)->toBe('90.0000');

    // Exactly filling the ceiling is allowed.
    createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '10'],
    ]);

    $line = queryProc($t, $f, new GetContract($contractId))->lines[0];
    expect($line->committed)->toBe('100.0000')
        ->and($line->remaining)->toBe('0.0000');
});

test('two lines of one PO against the same contract line are summed for the ceiling', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = consumptionActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);
    expect(fn () => createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '70'],
        ['contractLineId' => $lineIds[0], 'quantity' => '40'],
    ]))->toThrow(ContractCeilingExceeded::class);
});

test('a quantity-increasing amendment re-asserts the whole cumulative total; the line rolls back on breach', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = consumptionActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);
    issueContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '30'],
    ]); // PO A commits 30

    test()->actingAs($buyer);
    [$poB] = issueContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '40'],
    ]); // issuer left actingAs, holds .amend
    $issuerId = (string) Auth::id();
    $lineB = (string) TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poB)->value('id'));

    // 30 + 60 = 90 ≤ 100 — allowed.
    dispatchProc($t, $f, new AmendPurchaseOrder(
        purchaseOrderId: $poB, actorId: $issuerId, amendmentType: PoAmendment::TYPE_QUANTITY,
        reason: 'demand rose', newValue: '60', poLineId: $lineB,
    ));
    expect(queryProc($t, $f, new GetContract($contractId))->lines[0]->committed)->toBe('90.0000');

    // 30 + 80 = 110 > 100 — rejected, and the line stays at 60.
    expect(fn () => dispatchProc($t, $f, new AmendPurchaseOrder(
        purchaseOrderId: $poB, actorId: $issuerId, amendmentType: PoAmendment::TYPE_QUANTITY,
        reason: 'demand rose more', newValue: '80', poLineId: $lineB,
    )))->toThrow(ContractCeilingExceeded::class);

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->find($lineB));
    expect((string) $line->ordered_qty)->toBe('60.0000');
    expect(queryProc($t, $f, new GetContract($contractId))->lines[0]->committed)->toBe('90.0000');
});

test('INV-price — a contract-linked PO line cannot be amended to a different unit price', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = consumptionActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '1500', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);
    [$poId] = issueContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '10'],
    ]);
    $issuerId = (string) Auth::id();
    $lineId = (string) TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->value('id'));

    expect(fn () => dispatchProc($t, $f, new AmendPurchaseOrder(
        purchaseOrderId: $poId, actorId: $issuerId, amendmentType: PoAmendment::TYPE_PRICE,
        reason: 'negotiated down', newValue: '1400', poLineId: $lineId,
    )))->toThrow(ContractPriceIsAuthoritative::class);

    // Amending to the exact contract price is a permitted no-op.
    dispatchProc($t, $f, new AmendPurchaseOrder(
        purchaseOrderId: $poId, actorId: $issuerId, amendmentType: PoAmendment::TYPE_PRICE,
        reason: 'reaffirm', newValue: '1500.00', poLineId: $lineId,
    ));
    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->find($lineId));
    expect((string) $line->unit_price)->toBe('1500.00');
});

test('a CANCELLED PO frees its committed quantity', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = consumptionActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);
    [$poA] = issueContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '80'],
    ]);
    $issuer = Auth::user();

    test()->actingAs($buyer);
    expect(fn () => createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '30'],
    ]))->toThrow(ContractCeilingExceeded::class);

    test()->actingAs($issuer);
    dispatchProc($t, $f, new CancelPurchaseOrder($poA, (string) $issuer->id, 'supplier withdrew'));

    expect(queryProc($t, $f, new GetContract($contractId))->lines[0]->committed)->toBe('0.0000');

    test()->actingAs($buyer);
    createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '30'],
    ]);
    expect(queryProc($t, $f, new GetContract($contractId))->lines[0]->committed)->toBe('30.0000');
});

test('a contract line with no ceiling tracks consumption but never blocks', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = consumptionActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Open-ended', 'uom' => 'EACH', 'agreedUnitPrice' => '5'],
    ]);

    test()->actingAs($buyer);
    createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '999999'],
    ]);

    $line = queryProc($t, $f, new GetContract($contractId))->lines[0];
    expect($line->quantityCeiling)->toBeNull()
        ->and($line->committed)->toBe('999999.0000')
        ->and($line->remaining)->toBeNull();
});

test('GetContract surfaces committed / received / remaining; received tracks the goods-receipt path', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = consumptionActors();
    $itemId = (string) seedInventoryItem($t)->id;
    $location = seedStockLocation($t, $f);

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'STOCK', 'itemRef' => $itemId, 'uom' => 'BOX', 'agreedUnitPrice' => '1500', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);
    [, $poNumber] = issueContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '40'],
    ]);

    test()->actingAs($buyer);
    receiveAgainstPo($t, $f, (string) $buyer->id, $poNumber, $itemId, (string) $location->id, '15');

    $line = queryProc($t, $f, new GetContract($contractId))->lines[0];
    expect($line->committed)->toBe('40.0000')
        ->and($line->received)->toBe('15.0000')
        ->and($line->remaining)->toBe('60.0000');
});
