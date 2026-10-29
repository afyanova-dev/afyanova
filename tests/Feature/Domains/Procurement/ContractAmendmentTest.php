<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AmendContract;
use App\Domains\Procurement\Application\Command\CreateContract;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\RenewContract;
use App\Domains\Procurement\Application\Command\TerminateContract;
use App\Domains\Procurement\Application\Query\GetContract;
use App\Domains\Procurement\Application\Query\SearchContracts;
use App\Domains\Procurement\Domain\Exception\ContractAmendmentInvalid;
use App\Domains\Procurement\Domain\Exception\ContractCeilingBelowCommitted;
use App\Domains\Procurement\Domain\Exception\ContractExpiryNotExtended;
use App\Domains\Procurement\Domain\Exception\ContractNotUsable;
use App\Domains\Procurement\Domain\Exception\IllegalContractTransition;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractAmendment;
use App\Domains\Procurement\Domain\Model\ContractLine;
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
 * @return array{t: string, f: string, buyer: User, supplier: Supplier}
 */
function amendmentActors(): array
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

test('AmendContract raises a ceiling in place — same id / number, version + amendment_count bump, one log row', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);
    $before = TenantContextScope::run($t, null, fn () => Contract::query()->find($contractId));

    test()->actingAs($buyer);
    dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [['field' => 'quantity_ceiling', 'contractLineId' => $lineIds[0], 'value' => '500']],
        reason: 'Framework volume raised for FY26',
    ));

    $after = TenantContextScope::run($t, null, fn () => Contract::query()->find($contractId));
    expect((string) $after->contract_number)->toBe((string) $before->contract_number)
        ->and($after->status)->toBe(Contract::STATUS_ACTIVE)
        ->and((int) $after->amendment_count)->toBe(1)
        ->and((int) $after->version)->toBeGreaterThan((int) $before->version);

    $line = TenantContextScope::run($t, null, fn () => ContractLine::query()->find($lineIds[0]));
    expect((string) $line->quantity_ceiling)->toBe('500.0000');

    $summary = queryProc($t, $f, new GetContract($contractId));
    expect($summary->amendmentCount)->toBe(1)
        ->and($summary->amendments)->toHaveCount(1)
        ->and($summary->amendments[0]->amendmentType)->toBe(ContractAmendment::TYPE_CEILING)
        ->and($summary->amendments[0]->previousValue)->toBe('100.0000')
        ->and($summary->amendments[0]->newValue)->toBe('500.0000')
        ->and($summary->amendments[0]->reason)->toBe('Framework volume raised for FY26')
        ->and($summary->lines[0]->quantityCeiling)->toBe('500.0000');
});

test('a ceiling cannot be lowered below the line’s committed quantity', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();

    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);
    createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $contractId, [
        ['contractLineId' => $lineIds[0], 'quantity' => '70'],
    ]);

    expect(fn () => dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [['field' => 'quantity_ceiling', 'contractLineId' => $lineIds[0], 'value' => '60']],
        reason: 'trying to cut it',
    )))->toThrow(ContractCeilingBelowCommitted::class);

    // Lowering to exactly the committed amount is allowed.
    dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [['field' => 'quantity_ceiling', 'contractLineId' => $lineIds[0], 'value' => '70']],
        reason: 'cap at current commitment',
    ));
    expect(queryProc($t, $f, new GetContract($contractId))->lines[0]->quantityCeiling)->toBe('70.0000');
});

test('expiry_date can only be extended', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();
    [$contractId] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [], '+1 year');

    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [['field' => 'expiry_date', 'value' => date('Y-m-d', (int) strtotime('+1 month'))]],
        reason: 'shorten it',
    )))->toThrow(ContractExpiryNotExtended::class);

    $target = date('Y-m-d', (int) strtotime('+2 years'));
    dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [['field' => 'expiry_date', 'value' => $target]],
        reason: 'extend the framework a year',
    ));
    expect(queryProc($t, $f, new GetContract($contractId))->expiryDate)->toBe($target);
});

test('AmendContract can change terms + title + add a line in one call — one log row each', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();
    [$contractId] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Cover', 'uom' => 'EACH', 'agreedUnitPrice' => '10'],
    ]);

    test()->actingAs($buyer);
    dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [
            ['field' => 'terms', 'value' => 'Net 45; delivery DAP Mwanza'],
            ['field' => 'title', 'value' => 'Annual reagent supply (rev. 2)'],
            ['field' => 'add_line', 'line' => ['lineType' => 'SERVICE', 'description' => 'Emergency call-out', 'uom' => 'EACH', 'agreedUnitPrice' => '250000']],
        ],
        reason: 'annual review adjustments',
    ));

    $summary = queryProc($t, $f, new GetContract($contractId));
    expect($summary->terms)->toBe('Net 45; delivery DAP Mwanza')
        ->and($summary->title)->toBe('Annual reagent supply (rev. 2)')
        ->and($summary->lines)->toHaveCount(2)
        ->and($summary->lines[1]->lineNo)->toBe(2)
        ->and($summary->lines[1]->committed)->toBe('0.0000')
        ->and($summary->amendmentCount)->toBe(1)   // one AmendContract call
        ->and($summary->amendments)->toHaveCount(3) // three change rows
        ->and(collect($summary->amendments)->pluck('amendmentType')->all())
        ->toBe([ContractAmendment::TYPE_TERMS, ContractAmendment::TYPE_TITLE, ContractAmendment::TYPE_LINE_ADDED]);
});

test('AmendContract is refused on a non-ACTIVE contract and for an unknown line', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();

    // DRAFT contract (not activated).
    $draftId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreateContract(
        contractId: $draftId, actorId: (string) $buyer->id, supplierId: (string) $supplier->id,
        title: 'x', effectiveDate: '2026-01-01', expiryDate: '2030-01-01',
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'uom' => 'EACH', 'agreedUnitPrice' => '10']],
    ));
    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new AmendContract(
        contractId: $draftId, actorId: (string) $buyer->id,
        changes: [['field' => 'title', 'value' => 'z']], reason: 'r',
    )))->toThrow(IllegalContractTransition::class);

    [$contractId] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id);
    test()->actingAs($buyer);
    expect(fn () => dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [['field' => 'quantity_ceiling', 'contractLineId' => (string) Str::uuid7(), 'value' => '5']],
        reason: 'r',
    )))->toThrow(ContractAmendmentInvalid::class);
});

test('RenewContract mints a superseding contract — old → SUPERSEDED, fresh line ids, zero consumption, PO linkage preserved', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();

    [$oldId, $oldLineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);

    test()->actingAs($buyer);
    $poId = createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $oldId, [
        ['contractLineId' => $oldLineIds[0], 'quantity' => '40'],
    ]);

    $newId = (string) Str::uuid7();
    dispatchProc($t, $f, new RenewContract(
        newContractId: $newId, contractId: $oldId, actorId: (string) $buyer->id,
        effectiveDate: date('Y-m-d', (int) strtotime('+11 months')),
        expiryDate: date('Y-m-d', (int) strtotime('+23 months')),
        reason: 'renew the framework for another year',
    ));

    $old = queryProc($t, $f, new GetContract($oldId));
    $new = queryProc($t, $f, new GetContract($newId));

    expect($old->status)->toBe(Contract::STATUS_SUPERSEDED)
        ->and($old->supersededByContractId)->toBe($newId)
        ->and($old->lines[0]->committed)->toBe('40.0000'); // the old version keeps its consumption

    expect($new->status)->toBe(Contract::STATUS_ACTIVE)
        ->and($new->supersedesContractId)->toBe($oldId)
        ->and($new->contractNumber)->not->toBe($old->contractNumber)
        ->and($new->supplierId)->toBe((string) $supplier->id)
        ->and($new->lines)->toHaveCount(1)
        ->and($new->lines[0]->lineId)->not->toBe($oldLineIds[0])   // FRESH identity
        ->and($new->lines[0]->agreedUnitPrice)->toBe('100.00')
        ->and($new->lines[0]->quantityCeiling)->toBe('100.0000')
        ->and($new->lines[0]->committed)->toBe('0.0000');          // zero consumption

    // The historical PO is untouched — still linked to the OLD contract + line.
    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect((string) $po->contract_id)->toBe($oldId);
    $poLine = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->first());
    expect((string) $poLine->contract_line_id)->toBe($oldLineIds[0]);
});

test('a SUPERSEDED contract cannot price a new PO but the renewal can; SUPERSEDED is terminal', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();
    [$oldId, $oldLineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id);

    $newId = (string) Str::uuid7();
    test()->actingAs($buyer);
    dispatchProc($t, $f, new RenewContract(
        newContractId: $newId, contractId: $oldId, actorId: (string) $buyer->id,
        effectiveDate: date('Y-m-d', (int) strtotime('-1 day')),
        expiryDate: date('Y-m-d', (int) strtotime('+2 years')),
        reason: 'renew',
    ));
    $newLineIds = TenantContextScope::run($t, null, fn (): array => ContractLine::query()
        ->where('contract_id', $newId)->orderBy('line_no')->pluck('id')->map(static fn ($id): string => (string) $id)->all());

    // New PO on the SUPERSEDED contract → refused.
    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['contractLineId' => $oldLineIds[0], 'quantity' => '1']], contractId: $oldId,
    )))->toThrow(ContractNotUsable::class);

    // New PO on the renewal → fine.
    createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $newId, [
        ['contractLineId' => $newLineIds[0], 'quantity' => '1'],
    ]);
    expect(queryProc($t, $f, new GetContract($newId))->lines[0]->committed)->toBe('1.0000');

    // SUPERSEDED cannot be terminated or renewed again.
    expect(fn () => dispatchProc($t, $f, new TerminateContract($oldId, (string) $buyer->id, 'too late')))
        ->toThrow(IllegalContractTransition::class);
    expect(fn () => dispatchProc($t, $f, new RenewContract(
        newContractId: (string) Str::uuid7(), contractId: $oldId, actorId: (string) $buyer->id,
        effectiveDate: date('Y-m-d', (int) strtotime('-1 day')), expiryDate: date('Y-m-d', (int) strtotime('+3 years')),
        reason: 'again',
    )))->toThrow(IllegalContractTransition::class);

    // SearchContracts matches SUPERSEDED.
    expect(queryProc($t, $f, new SearchContracts(status: Contract::STATUS_SUPERSEDED)))->toHaveCount(1);
});

test('pcr_contract_amendments is append-only at the database', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = amendmentActors();
    [$contractId, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ]);
    test()->actingAs($buyer);
    dispatchProc($t, $f, new AmendContract(
        contractId: $contractId, actorId: (string) $buyer->id,
        changes: [['field' => 'quantity_ceiling', 'contractLineId' => $lineIds[0], 'value' => '250']],
        reason: 'raise it',
    ));

    $policies = DB::table('pg_policies')->where('tablename', 'pcr_contract_amendments')->pluck('policyname')->all();
    expect($policies)->toContain('pcr_contract_amendments_no_update')
        ->and($policies)->toContain('pcr_contract_amendments_no_delete');

    $raw = DB::connection('pgsql_raw');
    $raw->transaction(function () use ($raw, $t, $contractId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$t]);
        expect($raw->table('pcr_contract_amendments')->where('contract_id', $contractId)->update(['reason' => 'x']))->toBe(0);
        expect($raw->table('pcr_contract_amendments')->where('contract_id', $contractId)->delete())->toBe(0);
    });

    // The audit row for an amendment carries only coded field names.
    $row = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.contract_amended')->latest('id')->first());
    $metadata = json_decode((string) $row->metadata, true);
    expect($metadata['change_count'])->toBe(1)
        ->and($metadata['fields'])->toBe(['quantity_ceiling'])
        ->and($row->reason)->toBe('raise it');
});
