<?php

declare(strict_types=1);

use App\Domains\Insurance\Application\Command\RegisterMemberCoverage;
use App\Domains\Insurance\Application\Command\SetPayerPlanStatus;
use App\Domains\Insurance\Application\Command\SetPayerStatus;
use App\Domains\Insurance\Application\Command\TerminateMemberCoverage;
use App\Domains\Insurance\Application\Command\UpdateMemberCoverage;
use App\Domains\Insurance\Application\Query\GetMemberCoverage;
use App\Domains\Insurance\Application\Query\GetPatientCoverages;
use App\Domains\Insurance\Application\Query\SearchMemberCoverages;
use App\Domains\Insurance\Domain\Exception\CoverageOutsidePlanWindow;
use App\Domains\Insurance\Domain\Exception\DuplicateActiveCoverage;
use App\Domains\Insurance\Domain\Exception\InsurancePatientNotFound;
use App\Domains\Insurance\Domain\Exception\MemberCoverageNotFound;
use App\Domains\Insurance\Domain\Exception\PayerOrPlanInactive;
use App\Domains\Insurance\Domain\Exception\PayerPlanNotFound;
use App\Domains\Insurance\Domain\Exception\PrincipalCoverageInvalid;
use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ins.2 — Member Coverage (ADR-199 D-A…D-E).
 *
 * @return array{t: string, f: string, a: string, patient: string, plan: string, payer: string}
 */
function coverageSetup(): array
{
    $fixture = insuranceFixture(['insurance.access', 'insurance.payers.manage', 'insurance.plans.manage', 'insurance.members.manage']);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $payer = insuranceRegisterPayer($t, $f, $a, 'NHIF', 'GOVERNMENT_PROGRAM');
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'STD');
    $patient = insurancePatient($t);

    return compact('t', 'f', 'a', 'patient', 'plan', 'payer');
}

test('a member coverage is registered ACTIVE, payer_id copied from the plan, and reads back', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan, 'payer' => $payer] = coverageSetup();

    $coverageId = insuranceRegisterCoverage($t, $f, $a, $patient, $plan, memberNumber: 'NHIF-9001');

    $cov = queryInsurance($t, $f, new GetMemberCoverage($coverageId));
    expect($cov->patientId)->toBe($patient)
        ->and($cov->planId)->toBe($plan)
        ->and($cov->payerId)->toBe($payer)          // copied from the plan
        ->and($cov->relationship)->toBe('PRIMARY_MEMBER')
        ->and($cov->priority)->toBe('PRIMARY')
        ->and($cov->memberNumber)->toBe('NHIF-9001')
        ->and($cov->status)->toBe(MemberCoverage::STATUS_ACTIVE);

    // no events
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', 'like', 'App\Domains\Insurance\\%')->count()))->toBe(0);
});

test('an unknown patient / plan is rejected', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'plan' => $plan] = coverageSetup();

    expect(fn () => insuranceRegisterCoverage($t, $f, $a, (string) Str::uuid7(), $plan))
        ->toThrow(InsurancePatientNotFound::class);

    $patient = insurancePatient($t);
    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $patient, (string) Str::uuid7()))
        ->toThrow(PayerPlanNotFound::class);
});

test('coverage cannot be registered against an INACTIVE plan or payer', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan, 'payer' => $payer] = coverageSetup();

    dispatchInsurance($t, $f, new SetPayerPlanStatus(planId: $plan, actorId: $a, status: 'INACTIVE'));
    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $patient, $plan))->toThrow(PayerOrPlanInactive::class);

    dispatchInsurance($t, $f, new SetPayerPlanStatus(planId: $plan, actorId: $a, status: 'ACTIVE'));
    dispatchInsurance($t, $f, new SetPayerStatus(payerId: $payer, actorId: $a, status: 'INACTIVE'));
    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $patient, $plan))->toThrow(PayerOrPlanInactive::class);
});

test('the coverage window must sit within the plan effective window', function () {
    $fixture = insuranceFixture(['insurance.access', 'insurance.payers.manage', 'insurance.plans.manage', 'insurance.members.manage']);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $payer = insuranceRegisterPayer($t, $f, $a, 'BOUNDED');
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'WIN', effectiveFrom: '2026-01-01', effectiveTo: '2026-12-31');
    $patient = insurancePatient($t);

    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $patient, $plan, effectiveFrom: '2025-06-01'))
        ->toThrow(CoverageOutsidePlanWindow::class);
    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $patient, $plan, effectiveFrom: '2026-06-01', effectiveTo: '2027-06-01'))
        ->toThrow(CoverageOutsidePlanWindow::class);

    $ok = insuranceRegisterCoverage($t, $f, $a, $patient, $plan, effectiveFrom: '2026-06-01', effectiveTo: '2026-12-31');
    expect(queryInsurance($t, $f, new GetMemberCoverage($ok))->effectiveFrom)->toBe('2026-06-01');
});

test('one ACTIVE coverage per priority per patient; PRIMARY + SECONDARY + TERTIARY coexist', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageSetup();
    $payer2 = insuranceRegisterPayer($t, $f, $a, 'SECOND');
    $plan2 = insuranceCreatePlan($t, $f, $a, $payer2, 'SEC');

    $primary = insuranceRegisterCoverage($t, $f, $a, $patient, $plan, priority: 'PRIMARY');
    insuranceRegisterCoverage($t, $f, $a, $patient, $plan2, priority: 'SECONDARY');

    // a second ACTIVE PRIMARY for the same patient -> rejected
    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $patient, $plan2, priority: 'PRIMARY'))
        ->toThrow(DuplicateActiveCoverage::class);

    // terminate the PRIMARY -> the slot frees, a replacement PRIMARY registers
    dispatchInsurance($t, $f, new TerminateMemberCoverage(memberCoverageId: $primary, actorId: $a, reason: 'switched plan'));
    $replacement = insuranceRegisterCoverage($t, $f, $a, $patient, $plan2, priority: 'PRIMARY');

    $all = queryInsurance($t, $f, new GetPatientCoverages($patient));
    expect($all)->toHaveCount(3)
        ->and($all[0]->memberCoverageId)->toBe($replacement)   // ACTIVE PRIMARY first
        ->and($all[2]->status)->toBe(MemberCoverage::STATUS_TERMINATED);   // terminated last
});

test('TerminateMemberCoverage is idempotent', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageSetup();
    $cov = insuranceRegisterCoverage($t, $f, $a, $patient, $plan);

    dispatchInsurance($t, $f, new TerminateMemberCoverage(memberCoverageId: $cov, actorId: $a, reason: 'first'));
    dispatchInsurance($t, $f, new TerminateMemberCoverage(memberCoverageId: $cov, actorId: $a, reason: 'second'));

    $c = queryInsurance($t, $f, new GetMemberCoverage($cov));
    expect($c->status)->toBe(MemberCoverage::STATUS_TERMINATED)
        ->and($c->terminationReason)->toBe('first');   // the first termination stands
});

test('a PRIMARY_MEMBER must not carry a principal; a dependent references an existing coverage', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $holder, 'plan' => $plan] = coverageSetup();

    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $holder, $plan, relationship: 'PRIMARY_MEMBER', principalMemberCoverageId: (string) Str::uuid7()))
        ->toThrow(PrincipalCoverageInvalid::class);

    $principal = insuranceRegisterCoverage($t, $f, $a, $holder, $plan);
    $dependent = insurancePatient($t);

    expect(fn () => insuranceRegisterCoverage($t, $f, $a, $dependent, $plan, relationship: 'CHILD', principalMemberCoverageId: (string) Str::uuid7()))
        ->toThrow(PrincipalCoverageInvalid::class);

    $childCov = insuranceRegisterCoverage($t, $f, $a, $dependent, $plan, relationship: 'CHILD', principalMemberCoverageId: $principal);
    expect(queryInsurance($t, $f, new GetMemberCoverage($childCov))->principalMemberCoverageId)->toBe($principal);
});

test('UpdateMemberCoverage patches external numbers + relationship + effective_to; SearchMemberCoverages filters', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan, 'payer' => $payer] = coverageSetup();
    $cov = insuranceRegisterCoverage($t, $f, $a, $patient, $plan);

    dispatchInsurance($t, $f, new UpdateMemberCoverage(
        memberCoverageId: $cov, actorId: $a, policyNumber: 'POL-77', relationship: 'SPOUSE', effectiveTo: '2030-01-01',
    ));
    $c = queryInsurance($t, $f, new GetMemberCoverage($cov));
    expect($c->policyNumber)->toBe('POL-77')
        ->and($c->relationship)->toBe('SPOUSE')
        ->and($c->effectiveTo)->toBe('2030-01-01');

    expect(queryInsurance($t, $f, new SearchMemberCoverages(payerId: $payer)))->toHaveCount(1);
    expect(queryInsurance($t, $f, new SearchMemberCoverages(status: 'TERMINATED')))->toHaveCount(0);
});

test('a coverage in one tenant is invisible to another', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageSetup();
    $cov = insuranceRegisterCoverage($t, $f, $a, $patient, $plan);

    ['t' => $t2, 'f' => $f2] = coverageSetup();
    expect(fn () => queryInsurance($t2, $f2, new GetMemberCoverage($cov)))
        ->toThrow(MemberCoverageNotFound::class);
});

test('a user without insurance.members.manage cannot register coverage; without .access cannot read', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageSetup();
    $cov = insuranceRegisterCoverage($t, $f, $a, $patient, $plan);

    $noManage = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.payers.manage']);
    test()->actingAs($noManage);
    expect(fn () => dispatchInsurance($t, $f, new RegisterMemberCoverage(
        memberCoverageId: (string) Str::uuid7(), actorId: (string) $noManage->id, patientId: $patient, planId: $plan,
        relationship: 'PRIMARY_MEMBER', priority: 'SECONDARY', effectiveFrom: '2021-01-01',
    )))->toThrow(AuthorizationException::class);

    $nobody = insuranceUserWithKeys($t, $f, []);
    test()->actingAs($nobody);
    expect(fn () => queryInsurance($t, $f, new GetMemberCoverage($cov)))->toThrow(AuthorizationException::class);
});
