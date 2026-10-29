<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\ActivateContract;
use App\Domains\Procurement\Application\Command\CreateContract;
use App\Domains\Procurement\Application\Command\TerminateContract;
use App\Domains\Procurement\Application\Query\GetContract;
use App\Domains\Procurement\Application\Query\SearchContracts;
use App\Domains\Procurement\Domain\Exception\ContractDatesInvalid;
use App\Domains\Procurement\Domain\Exception\ContractSelfActivationNotPermitted;
use App\Domains\Procurement\Domain\Exception\IllegalContractTransition;
use App\Domains\Procurement\Domain\Exception\SupplierNotOrderable;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, creator: User, supplier: Supplier}
 */
function contractActors(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access', 'procurement.contracts.manage',
    ]);
    $t = (string) $tenant->id;

    return [
        't' => $t,
        'f' => (string) $facility->id,
        'creator' => $creator,
        'supplier' => seedSupplier($t, ['status' => Supplier::STATUS_ACTIVE]),
    ];
}

test('DRAFT → ACTIVE needs a different actor (§24 / §228); the audit metadata is coded-only', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier] = contractActors();

    $contractId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreateContract(
        contractId: $contractId, actorId: (string) $creator->id, supplierId: (string) $supplier->id,
        title: 'Annual reagent supply', effectiveDate: '2026-01-01', expiryDate: '2027-01-01',
        lines: [
            ['lineType' => 'SERVICE', 'description' => 'Analyser cover', 'uom' => 'EACH', 'agreedUnitPrice' => '4200000'],
            ['lineType' => 'STOCK', 'itemRef' => (string) seedInventoryItem($t)->id, 'uom' => 'BOX', 'agreedUnitPrice' => '1500', 'quantityCeiling' => '500'],
        ],
        reason: 'Sole distributor in-country', terms: 'Net 30; delivery DAP Dar es Salaam',
    ));

    // The creator may not activate their own contract.
    expect(fn () => dispatchProc($t, $f, new ActivateContract($contractId, (string) $creator->id)))
        ->toThrow(ContractSelfActivationNotPermitted::class);

    $activator = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $activator->id, ['procurement.access', 'procurement.contracts.manage']);
    test()->actingAs($activator);
    dispatchProc($t, $f, new ActivateContract($contractId, (string) $activator->id));

    $contract = TenantContextScope::run($t, null, fn () => Contract::query()->find($contractId));
    expect($contract->status)->toBe(Contract::STATUS_ACTIVE)
        ->and($contract->activated_by_user_id)->toBe((string) $activator->id)
        ->and((string) $contract->contract_number)->toStartWith('CON-')
        ->and((int) $contract->version)->toBeGreaterThan(0);

    $row = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.contract_created')->latest('id')->first());
    $metadata = json_decode((string) $row->metadata, true);
    expect(array_keys($metadata))->toEqualCanonicalizing(['supplier_id', 'line_count'])
        ->and($metadata['supplier_id'])->toBe((string) $supplier->id)
        ->and($metadata['line_count'])->toBe(2)
        ->and(json_encode($metadata))->not->toContain('distributor')
        ->and(json_encode($metadata))->not->toContain('Net 30');
});

test('reason / terms are ROW-ONLY — stored on the row, never in the create event stream', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier] = contractActors();

    $contractId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreateContract(
        contractId: $contractId, actorId: (string) $creator->id, supplierId: (string) $supplier->id,
        title: 'x', effectiveDate: '2026-01-01', expiryDate: '2027-01-01',
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'uom' => 'EACH', 'agreedUnitPrice' => '10']],
        reason: 'confidential-sourcing-rationale', terms: 'confidential-payment-terms',
    ));

    // Contract records NO event (ADR-176 D-G) — nothing in the outbox.
    $events = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->count());
    expect($events)->toBe(0);

    $summary = queryProc($t, $f, new GetContract($contractId));
    expect($summary->reason)->toBe('confidential-sourcing-rationale')
        ->and($summary->terms)->toBe('confidential-payment-terms');
});

test('effective_date must be before expiry_date', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier] = contractActors();

    expect(fn () => dispatchProc($t, $f, new CreateContract(
        contractId: (string) Str::uuid7(), actorId: (string) $creator->id, supplierId: (string) $supplier->id,
        title: 'x', effectiveDate: '2027-01-01', expiryDate: '2026-01-01',
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'uom' => 'EACH', 'agreedUnitPrice' => '10']],
    )))->toThrow(ContractDatesInvalid::class);
});

test('a non-ACTIVE supplier cannot be put on contract', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator] = contractActors();
    $suspended = seedSupplier($t, ['status' => Supplier::STATUS_SUSPENDED]);

    expect(fn () => dispatchProc($t, $f, new CreateContract(
        contractId: (string) Str::uuid7(), actorId: (string) $creator->id, supplierId: (string) $suspended->id,
        title: 'x', effectiveDate: '2026-01-01', expiryDate: '2027-01-01',
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'uom' => 'EACH', 'agreedUnitPrice' => '10']],
    )))->toThrow(SupplierNotOrderable::class);
});

test('a DRAFT contract is immutable — terminate + recreate; TERMINATED is terminal', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier] = contractActors();

    $contractId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreateContract(
        contractId: $contractId, actorId: (string) $creator->id, supplierId: (string) $supplier->id,
        title: 'x', effectiveDate: '2026-01-01', expiryDate: '2027-01-01',
        lines: [['lineType' => 'SERVICE', 'description' => 'y', 'uom' => 'EACH', 'agreedUnitPrice' => '10']],
    ));

    // Terminate straight from DRAFT with a mandatory reason.
    dispatchProc($t, $f, new TerminateContract($contractId, (string) $creator->id, 'superseded before signature'));

    $contract = TenantContextScope::run($t, null, fn () => Contract::query()->find($contractId));
    expect($contract->status)->toBe(Contract::STATUS_TERMINATED)
        ->and($contract->termination_reason)->toBe('superseded before signature');

    // No transition out of TERMINATED.
    $other = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $other->id, ['procurement.access', 'procurement.contracts.manage']);
    test()->actingAs($other);
    expect(fn () => dispatchProc($t, $f, new ActivateContract($contractId, (string) $other->id)))
        ->toThrow(IllegalContractTransition::class);
    expect(fn () => dispatchProc($t, $f, new TerminateContract($contractId, (string) $other->id, 'again')))
        ->toThrow(IllegalContractTransition::class);
});

test('GetContract / SearchContracts surface the computed isExpired flag and the coded lifecycle', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier] = contractActors();

    [$contractId] = seedActiveContract($t, $f, (string) $creator->id, (string) $supplier->id, [
        ['lineType' => 'SERVICE', 'description' => 'Cover', 'uom' => 'EACH', 'agreedUnitPrice' => '1000', 'quantityCeiling' => '50'],
    ]);

    $summary = queryProc($t, $f, new GetContract($contractId));
    expect($summary->status)->toBe(Contract::STATUS_ACTIVE)
        ->and($summary->isExpired)->toBeFalse()
        ->and($summary->poCount)->toBe(0)
        ->and($summary->lines)->toHaveCount(1)
        ->and($summary->lines[0]->agreedUnitPrice)->toBe('1000.00')
        ->and($summary->lines[0]->quantityCeiling)->toBe('50.0000');

    $found = queryProc($t, $f, new SearchContracts(supplierId: (string) $supplier->id, status: Contract::STATUS_ACTIVE));
    expect($found)->toHaveCount(1)->and($found[0]->contractId)->toBe($contractId);

    expect(queryProc($t, $f, new SearchContracts(status: Contract::STATUS_TERMINATED)))->toHaveCount(0);
});

test('pcr_contracts is tenant-isolated (FORCE RLS)', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'pcr_contracts')->pluck('policyname')->all();
    expect($policies)->toContain('tenant_isolation');

    $forced = DB::selectOne("select relforcerowsecurity as f from pg_class where relname = 'pcr_contracts'");
    expect($forced->f)->toBeTrue();
});
