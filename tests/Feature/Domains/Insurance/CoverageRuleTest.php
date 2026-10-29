<?php

declare(strict_types=1);

use App\Domains\Insurance\Application\Command\EndPlanCoverageRule;
use App\Domains\Insurance\Application\Command\SetPlanCoverageRule;
use App\Domains\Insurance\Application\Query\EvaluateCoverage;
use App\Domains\Insurance\Application\Query\GetPlanCoverageRules;
use App\Domains\Insurance\Domain\Exception\CoverageRuleEffectiveDateNotAfterCurrent;
use App\Domains\Insurance\Domain\Exception\InvalidCoverageRule;
use App\Domains\Insurance\Domain\Exception\PayerPlanNotFound;
use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\ValueObject\CoverageConfidence;
use App\Domains\Insurance\Domain\ValueObject\CoverageDecisionStatus;
use App\Domains\Insurance\Domain\ValueObject\CoverageSource;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ins.3 — Coverage rules + the CoverageDecision read model (ADR-200 D-A…D-G).
 *
 * @return array{t: string, f: string, a: string, patient: string, plan: string, payer: string}
 */
function coverageRuleSetup(): array
{
    $fixture = insuranceFixture([
        'insurance.access', 'insurance.payers.manage', 'insurance.plans.manage',
        'insurance.members.manage', 'insurance.coverage.manage',
    ]);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $payer = insuranceRegisterPayer($t, $f, $a, 'NHIF', 'GOVERNMENT_PROGRAM');
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'STD');
    $patient = insurancePatient($t);
    insuranceRegisterCoverage($t, $f, $a, $patient, $plan);

    return compact('t', 'f', 'a', 'patient', 'plan', 'payer');
}

test('a coverage rule is set CURRENT; a later SetPlanCoverageRule supersedes it (exclusive effective_to)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'plan' => $plan] = coverageRuleSetup();

    $r1 = insuranceSetCoverageRule($t, $f, $a, $plan, 'LABORATORY', 'COVERED', '2024-01-01', coveragePercent: 70);
    $r2 = insuranceSetCoverageRule($t, $f, $a, $plan, 'LABORATORY', 'COVERED', '2026-01-01', coveragePercent: 80);

    $rules = queryInsurance($t, $f, new GetPlanCoverageRules($plan, 'LABORATORY'));
    expect($rules)->toHaveCount(2)
        ->and($rules[0]->ruleId)->toBe($r2)                     // newest first
        ->and($rules[0]->effectiveTo)->toBeNull()
        ->and($rules[1]->ruleId)->toBe($r1)
        ->and($rules[1]->effectiveTo)->toBe('2026-01-01')       // exclusive
        ->and($rules[1]->status)->toBe(PlanCoverageRule::STATUS_SUPERSEDED);

    // no events
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', 'like', 'App\Domains\Insurance\\%')->count()))->toBe(0);
});

test('SetPlanCoverageRule rejects an effective date not strictly after the open row; and validates terms', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'plan' => $plan] = coverageRuleSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'RADIOLOGY', 'COVERED', '2026-01-01', coveragePercent: 80);

    expect(fn () => insuranceSetCoverageRule($t, $f, $a, $plan, 'RADIOLOGY', 'COVERED', '2026-01-01', coveragePercent: 90))
        ->toThrow(CoverageRuleEffectiveDateNotAfterCurrent::class);

    expect(fn () => new SetPlanCoverageRule(
        ruleId: (string) Str::uuid7(), actorId: $a, planId: $plan, serviceCategory: 'RADIOLOGY',
        coverageStatus: 'NOT_COVERED', effectiveFrom: '2027-01-01', coveragePercent: 50,
    ))->toThrow(InvalidCoverageRule::class);

    expect(fn () => new SetPlanCoverageRule(
        ruleId: (string) Str::uuid7(), actorId: $a, planId: $plan, serviceCategory: 'RADIOLOGY',
        coverageStatus: 'COVERED', effectiveFrom: '2027-01-01', coveragePercent: 150,
    ))->toThrow(InvalidCoverageRule::class);
});

test('SetPlanCoverageRule requires the plan to exist', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = coverageRuleSetup();

    expect(fn () => insuranceSetCoverageRule($t, $f, $a, (string) Str::uuid7(), 'LABORATORY'))
        ->toThrow(PayerPlanNotFound::class);
});

test('EvaluateCoverage: no ACTIVE member coverage -> NOT_COVERED, patient 100%', function () {
    $fixture = insuranceFixture(['insurance.access', 'insurance.payers.manage', 'insurance.plans.manage', 'insurance.coverage.manage']);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $payer = insuranceRegisterPayer($t, $f, $a, 'X');
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'X');
    $patient = insurancePatient($t);   // no coverage registered
    insuranceSetCoverageRule($t, $f, $a, $plan, 'CONSULTATION', 'COVERED', '2020-01-01', coveragePercent: 90);

    $d = insuranceEvaluateCoverage($t, $f, $patient, $plan, 'CONSULTATION', chargeAmount: '10000.00');
    expect($d->eligible)->toBeFalse()
        ->and($d->coverageStatus)->toBe(CoverageDecisionStatus::NOT_COVERED)
        ->and($d->confidence)->toBe(CoverageConfidence::CONFIRMED)
        ->and($d->payerResponsibilityEstimate)->toBe('0.00')
        ->and($d->patientResponsibilityEstimate)->toBe('10000.00');
});

test('EvaluateCoverage: eligible but no rule -> UNKNOWN as data, not an error', function () {
    ['t' => $t, 'f' => $f, 'patient' => $patient, 'plan' => $plan] = coverageRuleSetup();

    $d = insuranceEvaluateCoverage($t, $f, $patient, $plan, 'MEDICATION', chargeAmount: '5000.00');
    expect($d->eligible)->toBeTrue()
        ->and($d->coverageStatus)->toBe(CoverageDecisionStatus::UNKNOWN)
        ->and($d->confidence)->toBe(CoverageConfidence::UNKNOWN)
        ->and($d->coveragePercent)->toBeNull()
        ->and($d->patientResponsibilityEstimate)->toBe('5000.00')
        ->and($d->source)->toBe(CoverageSource::LOCAL_POLICY);
});

test('EvaluateCoverage: COVERED 80% with a 2000 co-pay splits HALF-UP; derived PARTIALLY_COVERED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageRuleSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'LABORATORY', 'COVERED', '2020-01-01', coveragePercent: 80, coPayAmount: '2000.00');

    // base = 10000 - 2000 = 8000; payer = round(8000 * 0.80) = 6400.00; patient = 3600.00
    $d = insuranceEvaluateCoverage($t, $f, $patient, $plan, 'LABORATORY', chargeAmount: '10000.00');
    expect($d->coverageStatus)->toBe(CoverageDecisionStatus::PARTIALLY_COVERED)
        ->and($d->coveragePercent)->toBe(80)
        ->and($d->coPayAmount)->toBe('2000.00')
        ->and($d->payerResponsibilityEstimate)->toBe('6400.00')
        ->and($d->patientResponsibilityEstimate)->toBe('3600.00')
        ->and($d->confidence)->toBe(CoverageConfidence::CONFIRMED);

    // no chargeAmount -> the estimate is null, the rule still returned
    $noAmount = insuranceEvaluateCoverage($t, $f, $patient, $plan, 'LABORATORY');
    expect($noAmount->payerResponsibilityEstimate)->toBeNull()
        ->and($noAmount->coveragePercent)->toBe(80);
});

test('EvaluateCoverage: a fixed benefit caps the payer amount', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageRuleSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'CONSULTATION', 'COVERED', '2020-01-01', fixedBenefitAmount: '15000.00');

    $d = insuranceEvaluateCoverage($t, $f, $patient, $plan, 'CONSULTATION', chargeAmount: '25000.00');
    expect($d->payerResponsibilityEstimate)->toBe('15000.00')
        ->and($d->patientResponsibilityEstimate)->toBe('10000.00');
});

test('EvaluateCoverage: REQUIRES_AUTHORIZATION -> authorizationRequired, PROVISIONAL confidence', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageRuleSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'RADIOLOGY', 'REQUIRES_AUTHORIZATION', '2020-01-01', coveragePercent: 100);

    $d = insuranceEvaluateCoverage($t, $f, $patient, $plan, 'RADIOLOGY', chargeAmount: '40000.00');
    expect($d->coverageStatus)->toBe(CoverageDecisionStatus::REQUIRES_AUTHORIZATION)
        ->and($d->authorizationRequired)->toBeTrue()
        ->and($d->confidence)->toBe(CoverageConfidence::PROVISIONAL)
        ->and($d->payerResponsibilityEstimate)->toBe('40000.00');   // computed as if covered
});

test('EvaluateCoverage: as-of-date re-derives the historical rule', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageRuleSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'PROCEDURE', 'COVERED', '2024-01-01', coveragePercent: 50);
    insuranceSetCoverageRule($t, $f, $a, $plan, 'PROCEDURE', 'COVERED', '2026-01-01', coveragePercent: 90);

    expect(insuranceEvaluateCoverage($t, $f, $patient, $plan, 'PROCEDURE', chargeAmount: '1000.00', onDate: '2025-06-01')->coveragePercent)->toBe(50);
    expect(insuranceEvaluateCoverage($t, $f, $patient, $plan, 'PROCEDURE', chargeAmount: '1000.00', onDate: '2026-06-01')->coveragePercent)->toBe(90);
    // before the first rule -> UNKNOWN
    expect(insuranceEvaluateCoverage($t, $f, $patient, $plan, 'PROCEDURE', onDate: '2023-06-01')->coverageStatus)->toBe(CoverageDecisionStatus::UNKNOWN);
});

test('EndPlanCoverageRule closes the open rule; later dates read UNKNOWN, earlier dates still resolve', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan] = coverageRuleSetup();
    $r1 = insuranceSetCoverageRule($t, $f, $a, $plan, 'BED_DAY', 'COVERED', '2024-01-01', coveragePercent: 60);

    dispatchInsurance($t, $f, new EndPlanCoverageRule(ruleId: $r1, actorId: $a, effectiveTo: '2025-01-01'));

    expect(insuranceEvaluateCoverage($t, $f, $patient, $plan, 'BED_DAY', onDate: '2024-06-01')->coverageStatus)->toBe(CoverageDecisionStatus::PARTIALLY_COVERED);
    expect(insuranceEvaluateCoverage($t, $f, $patient, $plan, 'BED_DAY', onDate: '2025-06-01')->coverageStatus)->toBe(CoverageDecisionStatus::UNKNOWN);
    expect(queryInsurance($t, $f, new GetPlanCoverageRules($plan, 'BED_DAY'))[0]->status)->toBe(PlanCoverageRule::STATUS_ENDED);

    // a fresh rule can be opened for the scope again
    $r2 = insuranceSetCoverageRule($t, $f, $a, $plan, 'BED_DAY', 'COVERED', '2025-06-01', coveragePercent: 100);
    expect(insuranceEvaluateCoverage($t, $f, $patient, $plan, 'BED_DAY', onDate: '2025-07-01')->coverageStatus)->toBe(CoverageDecisionStatus::COVERED);
    expect($r2)->not->toBe($r1);
});

test('a user without insurance.coverage.manage cannot set a rule; without .access cannot evaluate', function () {
    ['t' => $t, 'f' => $f, 'patient' => $patient, 'plan' => $plan] = coverageRuleSetup();

    $noManage = insuranceUserWithKeys($t, $f, ['insurance.access']);
    test()->actingAs($noManage);
    expect(fn () => dispatchInsurance($t, $f, new SetPlanCoverageRule(
        ruleId: (string) Str::uuid7(), actorId: (string) $noManage->id, planId: $plan,
        serviceCategory: 'LABORATORY', coverageStatus: 'COVERED', effectiveFrom: '2020-01-01', coveragePercent: 50,
    )))->toThrow(AuthorizationException::class);
    // but it CAN evaluate (coarse .access)
    expect(insuranceEvaluateCoverage($t, $f, $patient, $plan, 'LABORATORY')->eligible)->toBeTrue();

    $nobody = insuranceUserWithKeys($t, $f, []);
    test()->actingAs($nobody);
    expect(fn () => queryInsurance($t, $f, new EvaluateCoverage(patientId: $patient, planId: $plan, serviceCategory: 'LABORATORY')))
        ->toThrow(AuthorizationException::class);
});

test('coverage rules are tenant-isolated', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'plan' => $plan] = coverageRuleSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'LABORATORY', 'COVERED', '2020-01-01', coveragePercent: 80);

    ['t' => $t2, 'f' => $f2] = coverageRuleSetup();
    expect(queryInsurance($t2, $f2, new GetPlanCoverageRules($plan)))->toHaveCount(0);
});
