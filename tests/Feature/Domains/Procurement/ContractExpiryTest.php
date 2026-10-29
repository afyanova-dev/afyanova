<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AmendContract;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\RenewContract;
use App\Domains\Procurement\Application\Command\TerminateContract;
use App\Domains\Procurement\Application\Query\SearchContracts;
use App\Domains\Procurement\Domain\Exception\ContractNotUsable;
use App\Domains\Procurement\Domain\Exception\IllegalContractTransition;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, buyer: User, supplier: Supplier}
 */
function expiryActors(): array
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

function runExpirySweep(): void
{
    test()->artisan('procurement:expire-contracts', ['--once' => true])->assertSuccessful();
}

test('the sweep flips an ACTIVE past-expiry contract to EXPIRED and leaves a live one alone', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = expiryActors();

    [$liveId] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [], '+1 year');
    [$staleId] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [], '+1 day');
    // Push the stale one's expiry into the past directly (no command shortens a live term).
    TenantContextScope::run($t, null, fn () => Contract::query()->whereKey($staleId)
        ->update(['expiry_date' => now()->subDays(3)->toDateString()]));

    runExpirySweep();

    $live = TenantContextScope::run($t, null, fn () => Contract::query()->find($liveId));
    $stale = TenantContextScope::run($t, null, fn () => Contract::query()->find($staleId));
    expect($live->status)->toBe(Contract::STATUS_ACTIVE)
        ->and($stale->status)->toBe(Contract::STATUS_EXPIRED)
        ->and((int) $stale->version)->toBeGreaterThan((int) $live->version);
});

test('the sweep is idempotent and never touches a TERMINATED / SUPERSEDED contract', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = expiryActors();

    [$expiredId] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [], '+1 day');
    TenantContextScope::run($t, null, fn () => Contract::query()->whereKey($expiredId)
        ->update(['expiry_date' => now()->subDay()->toDateString()]));

    // A TERMINATED contract that is also past a (hypothetical) expiry.
    $terminated = TenantContextScope::run($t, null, fn () => Contract::factory()->forTenant($t)
        ->create(['supplier_id' => (string) $supplier->id, 'status' => Contract::STATUS_TERMINATED, 'expiry_date' => now()->subMonth()->toDateString()]));
    $superseded = TenantContextScope::run($t, null, fn () => Contract::factory()->forTenant($t)
        ->create(['supplier_id' => (string) $supplier->id, 'status' => Contract::STATUS_SUPERSEDED, 'expiry_date' => now()->subMonth()->toDateString()]));

    runExpirySweep();
    runExpirySweep(); // second pass — no error, no further change

    expect(TenantContextScope::run($t, null, fn () => Contract::query()->find($expiredId)->status))->toBe(Contract::STATUS_EXPIRED)
        ->and(TenantContextScope::run($t, null, fn () => Contract::query()->find($terminated->id)->status))->toBe(Contract::STATUS_TERMINATED)
        ->and(TenantContextScope::run($t, null, fn () => Contract::query()->find($superseded->id)->status))->toBe(Contract::STATUS_SUPERSEDED);
});

test('SearchContracts filters correctly once a contract is swept to EXPIRED', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = expiryActors();
    [$id] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [], '+1 day');
    TenantContextScope::run($t, null, fn () => Contract::query()->whereKey($id)
        ->update(['expiry_date' => now()->subDay()->toDateString()]));

    expect(queryProc($t, $f, new SearchContracts(status: Contract::STATUS_ACTIVE)))->toHaveCount(1);

    runExpirySweep();

    expect(queryProc($t, $f, new SearchContracts(status: Contract::STATUS_ACTIVE)))->toHaveCount(0)
        ->and(queryProc($t, $f, new SearchContracts(status: Contract::STATUS_EXPIRED)))->toHaveCount(1);
});

test('a swept EXPIRED contract cannot price a new PO, nor be amended / renewed / terminated', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = expiryActors();

    [$id, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ], '+1 day');
    TenantContextScope::run($t, null, fn () => Contract::query()->whereKey($id)
        ->update(['expiry_date' => now()->subDay()->toDateString()]));

    runExpirySweep();

    test()->actingAs($buyer);

    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $buyer->id, facilityId: $f, supplierId: (string) $supplier->id,
        lines: [['contractLineId' => $lineIds[0], 'quantity' => '1']], contractId: $id,
    )))->toThrow(ContractNotUsable::class);

    expect(fn () => dispatchProc($t, $f, new AmendContract(
        contractId: $id, actorId: (string) $buyer->id,
        changes: [['field' => 'title', 'value' => 'too late']], reason: 'r',
    )))->toThrow(IllegalContractTransition::class);

    expect(fn () => dispatchProc($t, $f, new RenewContract(
        newContractId: (string) Str::uuid7(), contractId: $id, actorId: (string) $buyer->id,
        effectiveDate: date('Y-m-d', (int) strtotime('-1 day')), expiryDate: date('Y-m-d', (int) strtotime('+1 year')),
        reason: 'the lapsed one needs a fresh CreateContract, not a renewal',
    )))->toThrow(IllegalContractTransition::class);

    expect(fn () => dispatchProc($t, $f, new TerminateContract($id, (string) $buyer->id, 'redundant')))
        ->toThrow(IllegalContractTransition::class);
});

test('an EXPIRED contract keeps its lines and its historical POs intact', function () {
    ['t' => $t, 'f' => $f, 'buyer' => $buyer, 'supplier' => $supplier] = expiryActors();

    [$id, $lineIds] = seedActiveContract($t, $f, (string) $buyer->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'uom' => 'EACH', 'agreedUnitPrice' => '100', 'quantityCeiling' => '100'],
    ], '+1 day');

    test()->actingAs($buyer);
    $poId = createContractPo($t, $f, (string) $buyer->id, (string) $supplier->id, $id, [
        ['contractLineId' => $lineIds[0], 'quantity' => '40'],
    ]);

    TenantContextScope::run($t, null, fn () => Contract::query()->whereKey($id)
        ->update(['expiry_date' => now()->subDay()->toDateString()]));
    runExpirySweep();

    $line = TenantContextScope::run($t, null, fn () => ContractLine::query()->find($lineIds[0]));
    $poLine = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->first());
    expect($line)->not->toBeNull()
        ->and((string) $poLine->contract_line_id)->toBe($lineIds[0]);
});
