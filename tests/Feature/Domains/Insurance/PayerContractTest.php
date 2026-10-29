<?php

declare(strict_types=1);

use App\Domains\Insurance\Application\Query\FindEffectivePayerTariff;
use App\Domains\Insurance\Application\Query\GetContractTariffs;
use App\Domains\Insurance\Application\Query\GetPayerContract;
use App\Domains\Insurance\Domain\Exception\IllegalPayerContractTransition;
use App\Domains\Insurance\Domain\Exception\InvalidPayerContractDates;
use App\Domains\Insurance\Domain\Exception\PayerContractNotActive;
use App\Domains\Insurance\Domain\Exception\PayerContractNotFound;
use App\Domains\Insurance\Domain\Exception\PayerTariffEffectiveDateNotAfterCurrent;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use App\Domains\Insurance\Domain\ValueObject\PayerTariffStatus;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Ins.6 — Payer Contract & Payer Tariff (ADR-203; ADR-112).
 *
 * @return array{t: string, f: string, a: string, user: User, payer: string}
 */
function payerContractSetup(): array
{
    $fixture = insuranceFixture([
        'insurance.access',
        'insurance.payers.manage',
        'insurance.plans.manage',
        'insurance.members.manage',
        'insurance.coverage.manage',
        'insurance.contracts.manage',
        'insurance.tariffs.manage',
    ]);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    /** @var User $user */
    $user = $fixture['user'];
    $payer = insuranceRegisterPayer($t, $f, $a, 'JUBILEE', 'INSURANCE');

    return compact('t', 'f', 'a', 'user', 'payer');
}

test('a payer contract is created with status DRAFT, PCT-%08d sequence minted, and zero domain events', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract(
        $t, $f, $a, $payer,
        name: 'Jubilee Provider Agreement 2026',
        effectiveFrom: '2026-01-01',
        effectiveTo: '2026-12-31',
        reference: 'AGR-JUB-001',
        terms: 'Net 30 payment terms',
    );

    $summary = queryInsurance($t, $f, new GetPayerContract($contractId));
    expect($summary->id)->toBe($contractId)
        ->and($summary->contractNumber)->toMatch('/^PCT-\d{8}$/')
        ->and($summary->payerId)->toBe($payer)
        ->and($summary->name)->toBe('Jubilee Provider Agreement 2026')
        ->and($summary->status)->toBe(PayerContractStatus::DRAFT)
        ->and($summary->effectiveFrom)->toBe('2026-01-01')
        ->and($summary->effectiveTo)->toBe('2026-12-31')
        ->and($summary->reference)->toBe('AGR-JUB-001')
        ->and($summary->terms)->toBe('Net 30 payment terms')
        ->and($summary->facilityId)->toBeNull();

    // Invariant: zero outbox domain events (ADR-137 / ADR-203 D-E)
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', 'like', 'App\Domains\Insurance\\%')->count()))->toBe(0);
});

test('ActivatePayerContract transitions DRAFT to ACTIVE', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract($t, $f, $a, $payer);
    insuranceActivateContract($t, $f, $a, $contractId);

    $summary = queryInsurance($t, $f, new GetPayerContract($contractId));
    expect($summary->status)->toBe(PayerContractStatus::ACTIVE);

    // Re-activating throws IllegalPayerContractTransition
    expect(fn () => insuranceActivateContract($t, $f, $a, $contractId))
        ->toThrow(IllegalPayerContractTransition::class);
});

test('TerminatePayerContract transitions ACTIVE to TERMINATED with reason', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract($t, $f, $a, $payer);
    insuranceActivateContract($t, $f, $a, $contractId);

    insuranceTerminateContract($t, $f, $a, $contractId, 'Contract renegotiation failed');

    $summary = queryInsurance($t, $f, new GetPayerContract($contractId));
    expect($summary->status)->toBe(PayerContractStatus::TERMINATED)
        ->and($summary->terminationReason)->toBe('Contract renegotiation failed')
        ->and($summary->terminatedAt)->not->toBeNull();

    // Cannot terminate already terminated contract
    expect(fn () => insuranceTerminateContract($t, $f, $a, $contractId, 'Another reason'))
        ->toThrow(IllegalPayerContractTransition::class);
});

test('CreatePayerContract rejects invalid date range when effective_to is before effective_from', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    expect(fn () => insuranceCreateContract(
        $t, $f, $a, $payer,
        effectiveFrom: '2026-06-01',
        effectiveTo: '2026-01-01',
    ))->toThrow(InvalidPayerContractDates::class);
});

test('SetPayerTariff creates an open tariff and supersedes prior open row with strict date ordering', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract($t, $f, $a, $payer);
    insuranceActivateContract($t, $f, $a, $contractId);

    // Set initial tariff
    $tariff1 = insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        amount: '50000.0000',
        effectiveFrom: '2026-01-01',
    );

    $tariffs = queryInsurance($t, $f, new GetContractTariffs($contractId));
    expect($tariffs)->toHaveCount(1)
        ->and($tariffs[0]->id)->toBe($tariff1)
        ->and($tariffs[0]->status)->toBe(PayerTariffStatus::CURRENT)
        ->and($tariffs[0]->amount)->toBe('50000.00')
        ->and($tariffs[0]->effectiveTo)->toBeNull();

    // Set replacement tariff effective 2026-07-01
    $tariff2 = insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        amount: '60000.0000',
        effectiveFrom: '2026-07-01',
    );

    $tariffsAfter = queryInsurance($t, $f, new GetContractTariffs($contractId));
    expect($tariffsAfter)->toHaveCount(2);

    $row1 = collect($tariffsAfter)->firstWhere('id', $tariff1);
    $row2 = collect($tariffsAfter)->firstWhere('id', $tariff2);

    expect($row1->status)->toBe(PayerTariffStatus::SUPERSEDED)
        ->and($row1->effectiveTo)->toBe('2026-07-01')
        ->and($row2->status)->toBe(PayerTariffStatus::CURRENT)
        ->and($row2->amount)->toBe('60000.00')
        ->and($row2->effectiveFrom)->toBe('2026-07-01')
        ->and($row2->effectiveTo)->toBeNull();
});

test('SetPayerTariff rejects effective date not strictly after current effective_from', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract($t, $f, $a, $payer);
    insuranceActivateContract($t, $f, $a, $contractId);

    insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        amount: '50000.0000',
        effectiveFrom: '2026-06-01',
    );

    // Same date rejected
    expect(fn () => insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        amount: '55000.0000',
        effectiveFrom: '2026-06-01',
    ))->toThrow(PayerTariffEffectiveDateNotAfterCurrent::class);

    // Earlier date rejected
    expect(fn () => insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        amount: '55000.0000',
        effectiveFrom: '2026-05-01',
    ))->toThrow(PayerTariffEffectiveDateNotAfterCurrent::class);
});

test('SetPayerTariff rejects tariff on non-active contract', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract($t, $f, $a, $payer);
    // Still in DRAFT

    expect(fn () => insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        amount: '50000.0000',
        effectiveFrom: '2026-01-01',
    ))->toThrow(PayerContractNotActive::class);
});

test('EndPayerTariff transitions CURRENT to ENDED with effective_to', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract($t, $f, $a, $payer);
    insuranceActivateContract($t, $f, $a, $contractId);

    $tariffId = insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::LABORATORY,
        amount: '25000.0000',
        effectiveFrom: '2026-01-01',
    );

    insuranceEndTariff($t, $f, $a, $tariffId, '2026-08-31');

    $tariffs = queryInsurance($t, $f, new GetContractTariffs($contractId));
    expect($tariffs[0]->status)->toBe(PayerTariffStatus::ENDED)
        ->and($tariffs[0]->effectiveTo)->toBe('2026-08-31');
});

test('insurance:expire-contracts command sweeps active contracts past effective_to', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    // Contract expired yesterday
    $pastDate = CarbonImmutable::now()->subDays(2)->toDateString();
    $yesterday = CarbonImmutable::now()->subDay()->toDateString();

    $contractId = insuranceCreateContract(
        $t, $f, $a, $payer,
        effectiveFrom: $pastDate,
        effectiveTo: $yesterday,
    );
    insuranceActivateContract($t, $f, $a, $contractId);

    // Before sweep: ACTIVE
    $before = queryInsurance($t, $f, new GetPayerContract($contractId));
    expect($before->status)->toBe(PayerContractStatus::ACTIVE);

    // Run sweep console command
    $exitCode = Artisan::call('insurance:expire-contracts', ['--once' => true]);
    expect($exitCode)->toBe(0);

    // After sweep: EXPIRED
    $after = queryInsurance($t, $f, new GetPayerContract($contractId));
    expect($after->status)->toBe(PayerContractStatus::EXPIRED);
});

test('CoverageEvaluator caps charge amount to contracted tariff and derives contractual adjustment', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    // 1. Create active contract and contracted tariff of 80,000 TZS for PROCEDURE
    $contractId = insuranceCreateContract($t, $f, $a, $payer, effectiveFrom: '2026-01-01');
    insuranceActivateContract($t, $f, $a, $contractId);
    insuranceSetTariff(
        $t, $f, $a, $contractId,
        serviceCategory: CoveredServiceCategory::PROCEDURE,
        amount: '80000.0000',
        effectiveFrom: '2026-01-01',
    );

    // 2. Create plan and 90% coverage rule (10% coinsurance)
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'PREMIUM');
    insuranceSetCoverageRule(
        $t, $f, $a, $plan,
        serviceCategory: CoveredServiceCategory::PROCEDURE,
        coveragePercent: 90,
    );

    // 3. Register member coverage for patient
    $patient = insurancePatient($t);
    insuranceRegisterCoverage($t, $f, $a, $patient, $plan, memberNumber: 'JUB-0099');

    // 4. Standard facility charge is 100,000 TZS
    $decision = insuranceEvaluateCoverage(
        $t, $f, $patient, $plan,
        serviceCategory: CoveredServiceCategory::PROCEDURE,
        chargeAmount: '100000.0000',
        onDate: '2026-06-15',
    );

    // Contracted tariff is 80,000.
    // Contractual adjustment = 100,000 - 80,000 = 20,000.
    // Allowed amount = 80,000.
    // Payer coverage (90% of 80,000) = 72,000.
    // Patient co-insurance (10% of 80,000) = 8,000.
    // Invariant: 72,000 + 8,000 + 20,000 = 100,000 (Charge).
    expect($decision->allowedAmount)->toBe('80000.00')
        ->and($decision->contractualAdjustmentEstimate)->toBe('20000.00')
        ->and($decision->payerResponsibilityEstimate)->toBe('72000.00')
        ->and($decision->patientResponsibilityEstimate)->toBe('8000.00');
});

test('facility-specific contract takes precedence over tenant-wide contract', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    // 1. Tenant-wide contract (facility_id = null): 50,000 TZS
    $cTenant = insuranceCreateContract($t, $f, $a, $payer, name: 'Tenant-wide Contract', contractFacilityId: null);
    insuranceActivateContract($t, $f, $a, $cTenant);
    insuranceSetTariff($t, $f, $a, $cTenant, CoveredServiceCategory::CONSULTATION, '50000.0000', '2026-01-01');

    // 2. Facility-specific contract (facility_id = $f): 40,000 TZS
    $cFacility = insuranceCreateContract($t, $f, $a, $payer, name: 'Facility Contract', contractFacilityId: $f);
    insuranceActivateContract($t, $f, $a, $cFacility);
    insuranceSetTariff($t, $f, $a, $cFacility, CoveredServiceCategory::CONSULTATION, '40000.0000', '2026-01-01');

    // Query for facility $f resolves 40,000
    $tariffFacility = queryInsurance($t, $f, new FindEffectivePayerTariff(
        payerId: $payer,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        onDate: '2026-06-01',
        facilityId: $f,
    ));
    expect($tariffFacility?->amount)->toBe('40000.00');

    // Query without facility resolves tenant-wide contract: 50,000
    $tariffTenant = queryInsurance($t, $f, new FindEffectivePayerTariff(
        payerId: $payer,
        serviceCategory: CoveredServiceCategory::CONSULTATION,
        onDate: '2026-06-01',
        facilityId: null,
    ));
    expect($tariffTenant?->amount)->toBe('50000.00');
});

test('RBAC enforces insurance.contracts.manage and insurance.tariffs.manage permissions', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    // User with only insurance.access cannot create contract
    $readOnlyUser = insuranceUserWithKeys($t, $f, ['insurance.access']);
    test()->actingAs($readOnlyUser);

    expect(fn () => insuranceCreateContract($t, $f, (string) $readOnlyUser->id, $payer))
        ->toThrow(AuthorizationException::class);

    // User with insurance.contracts.manage can create contract
    $contractsUser = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.contracts.manage']);
    test()->actingAs($contractsUser);

    $contractId = insuranceCreateContract($t, $f, (string) $contractsUser->id, $payer);
    insuranceActivateContract($t, $f, (string) $contractsUser->id, $contractId);

    // But cannot manage tariffs without insurance.tariffs.manage
    expect(fn () => insuranceSetTariff(
        $t, $f, (string) $contractsUser->id, $contractId,
        CoveredServiceCategory::CONSULTATION, '50000.0000', '2026-01-01',
    ))->toThrow(AuthorizationException::class);

    // User with insurance.tariffs.manage can set tariff
    $tariffsUser = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.tariffs.manage']);
    test()->actingAs($tariffsUser);

    $tariffId = insuranceSetTariff(
        $t, $f, (string) $tariffsUser->id, $contractId,
        CoveredServiceCategory::CONSULTATION, '50000.0000', '2026-01-01',
    );
    expect($tariffId)->not->toBeEmpty();
});

test('multi-tenant isolation prevents accessing contracts from another tenant', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer] = payerContractSetup();

    $contractId = insuranceCreateContract($t, $f, $a, $payer);

    // Tenant B
    $fixtureB = insuranceFixture(['insurance.access', 'insurance.contracts.manage']);
    $tB = (string) $fixtureB['tenant']->id;
    $fB = (string) $fixtureB['facility']->id;

    // Tenant B querying contract in Tenant A gets 404
    expect(fn () => queryInsurance($tB, $fB, new GetPayerContract($contractId)))
        ->toThrow(PayerContractNotFound::class);
});
