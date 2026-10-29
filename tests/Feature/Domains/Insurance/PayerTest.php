<?php

declare(strict_types=1);

use App\Domains\Insurance\Application\Command\CreatePayerPlan;
use App\Domains\Insurance\Application\Command\RegisterPayer;
use App\Domains\Insurance\Application\Command\SetPayerPlanStatus;
use App\Domains\Insurance\Application\Command\SetPayerStatus;
use App\Domains\Insurance\Application\Command\UpdatePayer;
use App\Domains\Insurance\Application\Command\UpdatePayerPlan;
use App\Domains\Insurance\Application\Query\GetPayer;
use App\Domains\Insurance\Application\Query\GetPayerPlan;
use App\Domains\Insurance\Application\Query\SearchPayerPlans;
use App\Domains\Insurance\Application\Query\SearchPayers;
use App\Domains\Insurance\Domain\Exception\PayerCodeTaken;
use App\Domains\Insurance\Domain\Exception\PayerNotFound;
use App\Domains\Insurance\Domain\Exception\PayerPlanCodeTaken;
use App\Domains\Insurance\Domain\Exception\PayerPlanNotFound;
use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\Model\Plan;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ins.1 — the Payer & Plan masters (ADR-198 D-G / D-H / D-I).
 *
 * @return array{t: string, f: string, a: string}
 */
function payerSetup(): array
{
    $fixture = insuranceFixture();

    return [
        't' => (string) $fixture['tenant']->id,
        'f' => (string) $fixture['facility']->id,
        'a' => (string) $fixture['user']->id,
    ];
}

test('a payer is registered ACTIVE and reads back', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();

    $payerId = insuranceRegisterPayer($t, $f, $a, 'NHIF', 'GOVERNMENT_PROGRAM');

    $payer = queryInsurance($t, $f, new GetPayer($payerId));
    expect($payer->code)->toBe('NHIF')
        ->and($payer->payerType)->toBe('GOVERNMENT_PROGRAM')
        ->and($payer->status)->toBe(Payer::STATUS_ACTIVE)
        ->and($payer->statusReason)->toBeNull();

    expect(Tenancy::run($t, fn () => DB::table('ins_payers')->where('id', $payerId)->value('created_by_user_id')))->toBe($a);
    // no events — configuration (the audit log covers it)
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', 'like', 'App\Domains\Insurance\\%')->count()))->toBe(0);
});

test('a duplicate payer code in the tenant is rejected', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    insuranceRegisterPayer($t, $f, $a, 'AAR');

    expect(fn () => insuranceRegisterPayer($t, $f, $a, 'AAR'))->toThrow(PayerCodeTaken::class);
});

test('SELF_PAY is not a valid payer type', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();

    expect(fn () => new RegisterPayer(
        payerId: (string) Str::uuid7(), actorId: $a, code: 'X', name: 'X', payerType: 'SELF_PAY',
    ))->toThrow(InvalidArgumentException::class);
});

test('UpdatePayer changes name + contact; SetPayerStatus deactivates with a reason and reactivates', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    $payerId = insuranceRegisterPayer($t, $f, $a, 'JUBILEE');

    dispatchInsurance($t, $f, new UpdatePayer(
        payerId: $payerId, actorId: $a, name: 'Jubilee Insurance', contactEmail: 'claims@jubilee.example',
    ));
    dispatchInsurance($t, $f, new SetPayerStatus(payerId: $payerId, actorId: $a, status: 'INACTIVE', reason: 'contract lapsed'));

    $payer = queryInsurance($t, $f, new GetPayer($payerId));
    expect($payer->name)->toBe('Jubilee Insurance')
        ->and($payer->contactEmail)->toBe('claims@jubilee.example')
        ->and($payer->status)->toBe(Payer::STATUS_INACTIVE)
        ->and($payer->statusReason)->toBe('contract lapsed');

    dispatchInsurance($t, $f, new SetPayerStatus(payerId: $payerId, actorId: $a, status: 'ACTIVE'));
    $payer = queryInsurance($t, $f, new GetPayer($payerId));
    expect($payer->status)->toBe(Payer::STATUS_ACTIVE)->and($payer->statusReason)->toBeNull();
});

test('a plan is created under a payer and reads back; benefit config is not modelled yet', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    $payerId = insuranceRegisterPayer($t, $f, $a, 'STRATEGIS');

    $planId = insuranceCreatePlan($t, $f, $a, $payerId, 'PREMIUM', effectiveFrom: '2026-01-01', effectiveTo: '2026-12-31');

    $plan = queryInsurance($t, $f, new GetPayerPlan($planId));
    expect($plan->payerId)->toBe($payerId)
        ->and($plan->code)->toBe('PREMIUM')
        ->and($plan->status)->toBe(Plan::STATUS_ACTIVE)
        ->and($plan->effectiveFrom)->toBe('2026-01-01')
        ->and($plan->effectiveTo)->toBe('2026-12-31');
});

test('a plan for a non-existent payer is rejected', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();

    expect(fn () => insuranceCreatePlan($t, $f, $a, (string) Str::uuid7(), 'X'))->toThrow(PayerNotFound::class);
});

test('plan code is unique per payer but free across payers', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    $payerA = insuranceRegisterPayer($t, $f, $a, 'PAYER-A');
    $payerB = insuranceRegisterPayer($t, $f, $a, 'PAYER-B');

    insuranceCreatePlan($t, $f, $a, $payerA, 'GOLD');
    expect(fn () => insuranceCreatePlan($t, $f, $a, $payerA, 'GOLD'))->toThrow(PayerPlanCodeTaken::class);

    // same code, different payer — allowed
    $planB = insuranceCreatePlan($t, $f, $a, $payerB, 'GOLD');
    expect(queryInsurance($t, $f, new GetPayerPlan($planB))->code)->toBe('GOLD');
});

test('an effective window with from after to is rejected; UpdatePayerPlan patches name + window', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    $payerId = insuranceRegisterPayer($t, $f, $a, 'MO');

    expect(fn () => insuranceCreatePlan($t, $f, $a, $payerId, 'BAD', effectiveFrom: '2027-01-01', effectiveTo: '2026-01-01'))
        ->toThrow(InvalidArgumentException::class);

    $planId = insuranceCreatePlan($t, $f, $a, $payerId, 'STD');
    dispatchInsurance($t, $f, new UpdatePayerPlan(planId: $planId, actorId: $a, name: 'Standard Plan', effectiveFrom: '2026-06-01'));
    $plan = queryInsurance($t, $f, new GetPayerPlan($planId));
    expect($plan->name)->toBe('Standard Plan')->and($plan->effectiveFrom)->toBe('2026-06-01');

    dispatchInsurance($t, $f, new SetPayerPlanStatus(planId: $planId, actorId: $a, status: 'INACTIVE', reason: 'withdrawn'));
    expect(queryInsurance($t, $f, new GetPayerPlan($planId))->status)->toBe(Plan::STATUS_INACTIVE);
});

test('search filters payers by type/status and plans by payer/status', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    $ins = insuranceRegisterPayer($t, $f, $a, 'INS-1', 'INSURANCE');
    $gov = insuranceRegisterPayer($t, $f, $a, 'GOV-1', 'GOVERNMENT_PROGRAM');
    dispatchInsurance($t, $f, new SetPayerStatus(payerId: $gov, actorId: $a, status: 'INACTIVE'));

    expect(queryInsurance($t, $f, new SearchPayers(payerType: 'INSURANCE')))->toHaveCount(1);
    expect(queryInsurance($t, $f, new SearchPayers(status: 'INACTIVE')))->toHaveCount(1);
    expect(queryInsurance($t, $f, new SearchPayers))->toHaveCount(2);

    insuranceCreatePlan($t, $f, $a, $ins, 'P1');
    insuranceCreatePlan($t, $f, $a, $ins, 'P2');
    insuranceCreatePlan($t, $f, $a, $gov, 'P3');
    expect(queryInsurance($t, $f, new SearchPayerPlans(payerId: $ins)))->toHaveCount(2);
    expect(queryInsurance($t, $f, new SearchPayerPlans))->toHaveCount(3);
});

test('a payer / plan in one tenant is invisible to another', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    $payerId = insuranceRegisterPayer($t, $f, $a, 'SHARED-CODE');

    ['t' => $t2, 'f' => $f2] = payerSetup();
    expect(fn () => queryInsurance($t2, $f2, new GetPayer($payerId)))->toThrow(PayerNotFound::class);
    // the same code is free in the other tenant
    $again = insuranceRegisterPayer($t2, $f2, (string) DB::table('users')->where('tenant_id', $t2)->value('id'), 'SHARED-CODE');
    expect(queryInsurance($t2, $f2, new GetPayer($again))->code)->toBe('SHARED-CODE');
});

test('GetPayerPlan on an unknown id is a 404', function () {
    ['t' => $t, 'f' => $f] = payerSetup();
    expect(fn () => queryInsurance($t, $f, new GetPayerPlan((string) Str::uuid7())))->toThrow(PayerPlanNotFound::class);
});

test('a user without insurance.payers.manage cannot register a payer; without .plans.manage cannot create a plan; without .access cannot read', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = payerSetup();
    $payerId = insuranceRegisterPayer($t, $f, $a, 'ACL');

    $planner = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.plans.manage']);
    test()->actingAs($planner);
    expect(fn () => dispatchInsurance($t, $f, new RegisterPayer(
        payerId: (string) Str::uuid7(), actorId: (string) $planner->id, code: 'NOPE', name: 'Nope', payerType: 'INSURANCE',
    )))->toThrow(AuthorizationException::class);

    $payerManager = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.payers.manage']);
    test()->actingAs($payerManager);
    expect(fn () => dispatchInsurance($t, $f, new CreatePayerPlan(
        planId: (string) Str::uuid7(), actorId: (string) $payerManager->id, payerId: $payerId, code: 'NOPE', name: 'Nope',
    )))->toThrow(AuthorizationException::class);

    $nobody = insuranceUserWithKeys($t, $f, []);
    test()->actingAs($nobody);
    expect(fn () => queryInsurance($t, $f, new GetPayer($payerId)))->toThrow(AuthorizationException::class);
});
