<?php

declare(strict_types=1);

use App\Domains\Insurance\Application\Command\ApproveAuthorization;
use App\Domains\Insurance\Application\Command\ExpireAuthorization;
use App\Domains\Insurance\Application\Command\RequestAuthorization;
use App\Domains\Insurance\Application\Command\TerminateMemberCoverage;
use App\Domains\Insurance\Application\Query\FindEffectiveAuthorization;
use App\Domains\Insurance\Application\Query\GetAuthorization;
use App\Domains\Insurance\Application\Query\GetPatientAuthorizations;
use App\Domains\Insurance\Application\Query\GetPendingAuthorizations;
use App\Domains\Insurance\Domain\Exception\AuthorizationDecisionIncomplete;
use App\Domains\Insurance\Domain\Exception\AuthorizationNotFound;
use App\Domains\Insurance\Domain\Exception\IllegalAuthorizationTransition;
use App\Domains\Insurance\Domain\Exception\MemberCoverageNotActive;
use App\Domains\Insurance\Domain\Exception\MemberCoverageNotFound;
use App\Domains\Insurance\Domain\Model\Authorization;
use App\Domains\Insurance\Domain\ValueObject\AuthorizationStatus;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ins.5 — Pre-Authorization (ADR-202 D-A…D-F).
 *
 * @return array{t: string, f: string, a: string, user: User, patient: string, plan: string, payer: string, coverage: string}
 */
function authorizationSetup(): array
{
    $fixture = insuranceFixture([
        'insurance.access',
        'insurance.payers.manage',
        'insurance.plans.manage',
        'insurance.members.manage',
        'insurance.authorizations.request',
        'insurance.authorizations.approve',
    ]);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    /** @var User $user */
    $user = $fixture['user'];
    $payer = insuranceRegisterPayer($t, $f, $a, 'AAR', 'INSURANCE');
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'EXEC');
    $patient = insurancePatient($t);
    $coverage = insuranceRegisterCoverage($t, $f, $a, $patient, $plan, memberNumber: 'AAR-7788');

    return compact('t', 'f', 'a', 'user', 'patient', 'plan', 'payer', 'coverage');
}

test('an authorization is requested with status REQUESTED, AUT-%08d minted, coverage data copied, and zero events emitted', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'plan' => $plan, 'payer' => $payer, 'coverage' => $coverage] = authorizationSetup();

    $authId = insuranceRequestAuthorization(
        $t, $f, $a, $coverage, CoveredServiceCategory::PROCEDURE,
        serviceDescription: 'Brain MRI',
        requestedQuantity: '1.0000',
        requestedAmount: '15000.0000',
    );

    $summary = queryInsurance($t, $f, new GetAuthorization($authId));
    expect($summary->id)->toBe($authId)
        ->and($summary->authorizationNumber)->toMatch('/^AUT-\d{8}$/')
        ->and($summary->memberCoverageId)->toBe($coverage)
        ->and($summary->patientId)->toBe($patient)
        ->and($summary->planId)->toBe($plan)
        ->and($summary->payerId)->toBe($payer)
        ->and($summary->serviceCategory)->toBe(CoveredServiceCategory::PROCEDURE)
        ->and($summary->serviceDescription)->toBe('Brain MRI')
        ->and($summary->status)->toBe(AuthorizationStatus::REQUESTED)
        ->and($summary->requestedQuantity)->toBe('1.0000')
        ->and($summary->requestedAmount)->toBe('15000.0000')
        ->and($summary->externalAuthorizationNumber)->toBeNull();

    // Invariant: NO domain events emitted (ADR-137 / ADR-202 D-E)
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', 'like', 'App\Domains\Insurance\\%')->count()))->toBe(0);
});

test('RequestAuthorization rejects a missing or non-active member coverage', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'coverage' => $coverage] = authorizationSetup();

    expect(fn () => insuranceRequestAuthorization($t, $f, $a, (string) Str::uuid7(), CoveredServiceCategory::CONSULTATION))
        ->toThrow(MemberCoverageNotFound::class);

    dispatchInsurance($t, $f, new TerminateMemberCoverage($coverage, $a, 'Terminated for non-payment'));

    expect(fn () => insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::CONSULTATION))
        ->toThrow(MemberCoverageNotActive::class);
});

test('ApproveAuthorization records external auth number and valid window, transitioning REQUESTED to APPROVED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'coverage' => $coverage] = authorizationSetup();

    $authId = insuranceRequestAuthorization(
        $t, $f, $a, $coverage, CoveredServiceCategory::PROCEDURE,
        requestedQuantity: '1.0000',
        requestedAmount: '10000.0000',
    );

    insuranceApproveAuthorization(
        $t, $f, $a, $authId,
        externalAuthorizationNumber: 'PAYER-AUTH-12345',
        validFrom: '2026-09-01',
        validTo: '2026-09-30',
        approvedQuantity: '1.0000',
        approvedAmount: '10000.0000',
        note: 'Approved in full',
    );

    $summary = queryInsurance($t, $f, new GetAuthorization($authId));
    expect($summary->status)->toBe(AuthorizationStatus::APPROVED)
        ->and($summary->externalAuthorizationNumber)->toBe('PAYER-AUTH-12345')
        ->and($summary->validFrom)->toBe('2026-09-01')
        ->and($summary->validTo)->toBe('2026-09-30')
        ->and($summary->decisionReason)->toBe('Approved in full')
        ->and($summary->decidedByUserId)->toBe($a);
});

test('ApproveAuthorization validates external number and validity window', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'coverage' => $coverage] = authorizationSetup();

    $authId = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::PROCEDURE);

    // Empty external auth number
    expect(fn () => insuranceApproveAuthorization(
        $t, $f, $a, $authId,
        externalAuthorizationNumber: '   ',
        validFrom: '2026-09-01',
        validTo: '2026-09-30',
    ))->toThrow(AuthorizationDecisionIncomplete::class);

    // valid_from after valid_to
    expect(fn () => insuranceApproveAuthorization(
        $t, $f, $a, $authId,
        externalAuthorizationNumber: 'AUTH-1',
        validFrom: '2026-10-01',
        validTo: '2026-09-01',
    ))->toThrow(AuthorizationDecisionIncomplete::class);
});

test('ApproveAuthorization derives PARTIALLY_APPROVED if approved qty or amount is below requested', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'coverage' => $coverage] = authorizationSetup();

    // Case 1: approved qty < requested qty
    $authId1 = insuranceRequestAuthorization(
        $t, $f, $a, $coverage, CoveredServiceCategory::PROCEDURE,
        requestedQuantity: '3.0000',
        requestedAmount: '10000.0000',
    );
    insuranceApproveAuthorization(
        $t, $f, $a, $authId1,
        externalAuthorizationNumber: 'PARTIAL-1',
        validFrom: '2026-09-01',
        validTo: '2026-09-30',
        approvedQuantity: '2.0000',
        approvedAmount: '10000.0000',
    );
    expect(queryInsurance($t, $f, new GetAuthorization($authId1))->status)
        ->toBe(AuthorizationStatus::PARTIALLY_APPROVED);

    // Case 2: approved amount < requested amount
    $authId2 = insuranceRequestAuthorization(
        $t, $f, $a, $coverage, CoveredServiceCategory::PROCEDURE,
        requestedQuantity: '1.0000',
        requestedAmount: '15000.0000',
    );
    insuranceApproveAuthorization(
        $t, $f, $a, $authId2,
        externalAuthorizationNumber: 'PARTIAL-2',
        validFrom: '2026-09-01',
        validTo: '2026-09-30',
        approvedQuantity: '1.0000',
        approvedAmount: '12000.0000',
    );
    expect(queryInsurance($t, $f, new GetAuthorization($authId2))->status)
        ->toBe(AuthorizationStatus::PARTIALLY_APPROVED);
});

test('DenyAuthorization transitions REQUESTED to DENIED with reason and note', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'coverage' => $coverage] = authorizationSetup();

    $authId = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::LABORATORY);

    insuranceDenyAuthorization($t, $f, $a, $authId, 'EXCLUSION_CRITERIA', 'Out of formulary test');

    $summary = queryInsurance($t, $f, new GetAuthorization($authId));
    expect($summary->status)->toBe(AuthorizationStatus::DENIED)
        ->and($summary->decisionReason)->toBe('EXCLUSION_CRITERIA — Out of formulary test');

    // Cannot approve an already denied authorization
    expect(fn () => insuranceApproveAuthorization($t, $f, $a, $authId, 'EXT', '2026-01-01', '2026-12-31'))
        ->toThrow(IllegalAuthorizationTransition::class);
});

test('CancelAuthorization cancels REQUESTED, APPROVED, or PARTIALLY_APPROVED authorizations', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'coverage' => $coverage] = authorizationSetup();

    // Cancel from REQUESTED
    $authId1 = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::MEDICATION);
    insuranceCancelAuthorization($t, $f, $a, $authId1, 'PATIENT_REQUEST');
    $summary1 = queryInsurance($t, $f, new GetAuthorization($authId1));
    expect($summary1->status)->toBe(AuthorizationStatus::CANCELLED)
        ->and($summary1->cancellationReason)->toBe('PATIENT_REQUEST');

    // Cancel from APPROVED
    $authId2 = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::MEDICATION);
    insuranceApproveAuthorization($t, $f, $a, $authId2, 'APP-1', '2026-01-01', '2026-12-31');
    insuranceCancelAuthorization($t, $f, $a, $authId2, 'PAYER_REVOCATION');
    $summary2 = queryInsurance($t, $f, new GetAuthorization($authId2));
    expect($summary2->status)->toBe(AuthorizationStatus::CANCELLED)
        ->and($summary2->cancellationReason)->toBe('PAYER_REVOCATION');

    // Cannot cancel already cancelled
    expect(fn () => insuranceCancelAuthorization($t, $f, $a, $authId2, 'AGAIN'))
        ->toThrow(IllegalAuthorizationTransition::class);
});

test('ExpireAuthorization flips lapsed authorization to EXPIRED, and is safe and idempotent', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'coverage' => $coverage] = authorizationSetup();

    $authId = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::PROCEDURE);
    insuranceApproveAuthorization(
        $t, $f, $a, $authId, 'EXP-1',
        validFrom: '2026-01-01',
        validTo: '2026-01-15', // lapsed in the past
    );

    // Dispatch ExpireAuthorization
    dispatchInsurance($t, $f, new ExpireAuthorization($authId));

    $summary = queryInsurance($t, $f, new GetAuthorization($authId));
    expect($summary->status)->toBe(AuthorizationStatus::EXPIRED)
        ->and($summary->expiredAt)->not->toBeNull();

    // Idempotent re-run
    dispatchInsurance($t, $f, new ExpireAuthorization($authId));
    expect(queryInsurance($t, $f, new GetAuthorization($authId))->status)->toBe(AuthorizationStatus::EXPIRED);
});

test('insurance:expire-authorizations console sweep flips lapsed authorizations across tenants', function () {
    ['t' => $t1, 'f' => $f1, 'a' => $a1, 'user' => $u1, 'coverage' => $cov1] = authorizationSetup();

    // Lapsed in Tenant 1
    $lapsed1 = insuranceRequestAuthorization($t1, $f1, $a1, $cov1, CoveredServiceCategory::PROCEDURE);
    insuranceApproveAuthorization($t1, $f1, $a1, $lapsed1, 'L1', '2026-01-01', '2026-01-10');

    // Active/current in Tenant 1
    $active1 = insuranceRequestAuthorization($t1, $f1, $a1, $cov1, CoveredServiceCategory::PROCEDURE);
    insuranceApproveAuthorization($t1, $f1, $a1, $active1, 'A1', '2026-01-01', '2026-12-31');

    ['t' => $t2, 'f' => $f2, 'a' => $a2, 'user' => $u2, 'coverage' => $cov2] = authorizationSetup();

    // Lapsed in Tenant 2
    $lapsed2 = insuranceRequestAuthorization($t2, $f2, $a2, $cov2, CoveredServiceCategory::CONSULTATION);
    insuranceApproveAuthorization($t2, $f2, $a2, $lapsed2, 'L2', '2026-02-01', '2026-02-15');

    Artisan::call('insurance:expire-authorizations', ['--once' => true]);

    test()->actingAs($u1);
    expect(queryInsurance($t1, $f1, new GetAuthorization($lapsed1))->status)->toBe(AuthorizationStatus::EXPIRED)
        ->and(queryInsurance($t1, $f1, new GetAuthorization($active1))->status)->toBe(AuthorizationStatus::APPROVED);

    test()->actingAs($u2);
    expect(queryInsurance($t2, $f2, new GetAuthorization($lapsed2))->status)->toBe(AuthorizationStatus::EXPIRED);
});

test('FindEffectiveAuthorization resolves effective authorization matching date and returns null when none', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'coverage' => $coverage] = authorizationSetup();

    $authId = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::RADIOLOGY);
    insuranceApproveAuthorization(
        $t, $f, $a, $authId, 'RAD-AUTH',
        validFrom: '2026-09-01',
        validTo: '2026-09-30',
    );

    // On effective date
    $effective = queryInsurance($t, $f, new FindEffectiveAuthorization($patient, CoveredServiceCategory::RADIOLOGY, '2026-09-15'));
    expect($effective)->not->toBeNull()
        ->and($effective?->id)->toBe($authId)
        ->and($effective?->externalAuthorizationNumber)->toBe('RAD-AUTH');

    // Before valid_from
    expect(queryInsurance($t, $f, new FindEffectiveAuthorization($patient, CoveredServiceCategory::RADIOLOGY, '2026-08-31')))
        ->toBeNull();

    // After valid_to
    expect(queryInsurance($t, $f, new FindEffectiveAuthorization($patient, CoveredServiceCategory::RADIOLOGY, '2026-10-01')))
        ->toBeNull();

    // Different category
    expect(queryInsurance($t, $f, new FindEffectiveAuthorization($patient, CoveredServiceCategory::PROCEDURE, '2026-09-15')))
        ->toBeNull();
});

test('worklists: GetPatientAuthorizations returns active first, then newest; GetPendingAuthorizations returns REQUESTED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'coverage' => $coverage] = authorizationSetup();

    $pendingId = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::CONSULTATION);
    $approvedId = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::PROCEDURE);
    insuranceApproveAuthorization($t, $f, $a, $approvedId, 'APP-W', '2026-01-01', '2026-12-31');

    $deniedId = insuranceRequestAuthorization($t, $f, $a, $coverage, CoveredServiceCategory::LABORATORY);
    insuranceDenyAuthorization($t, $f, $a, $deniedId, 'DENIED');

    // GetPatientAuthorizations
    $patientAuths = queryInsurance($t, $f, new GetPatientAuthorizations($patient));
    expect($patientAuths)->toHaveCount(3);
    // Active/effective first (APPROVED), then REQUESTED, then DENIED
    expect($patientAuths[0]->status)->toBe(AuthorizationStatus::APPROVED)
        ->and($patientAuths[1]->status)->toBe(AuthorizationStatus::REQUESTED)
        ->and($patientAuths[2]->status)->toBe(AuthorizationStatus::DENIED);

    // GetPendingAuthorizations
    $pendingAuths = queryInsurance($t, $f, new GetPendingAuthorizations);
    $pendingIds = array_map(fn ($p) => $p->id, $pendingAuths);
    expect($pendingIds)->toContain($pendingId)
        ->and($pendingIds)->not->toContain($approvedId)
        ->and($pendingIds)->not->toContain($deniedId);
});

test('RBAC and tenant isolation: user needs permissions and cannot access other tenants', function () {
    ['t' => $t1, 'f' => $f1, 'a' => $a1, 'user' => $u1, 'coverage' => $cov1] = authorizationSetup();

    $authId = insuranceRequestAuthorization($t1, $f1, $a1, $cov1, CoveredServiceCategory::PROCEDURE);

    // User in tenant 1 without insurance.authorizations.request
    $readOnlyUser = insuranceUserWithKeys($t1, $f1, ['insurance.access']);
    test()->actingAs($readOnlyUser);

    expect(fn () => dispatchInsurance($t1, $f1, new RequestAuthorization(
        (string) Str::uuid7(), $readOnlyUser->id, $cov1, CoveredServiceCategory::PROCEDURE,
    )))->toThrow(AuthorizationException::class);

    // User without insurance.authorizations.approve
    expect(fn () => dispatchInsurance($t1, $f1, new ApproveAuthorization(
        $authId, $readOnlyUser->id, 'AUTH-X', '2026-01-01', '2026-12-31',
    )))->toThrow(AuthorizationException::class);

    // User without insurance.access
    $noAccessUser = insuranceUserWithKeys($t1, $f1, ['insurance.authorizations.request']);
    test()->actingAs($noAccessUser);

    expect(fn () => queryInsurance($t1, $f1, new GetAuthorization($authId)))
        ->toThrow(AuthorizationException::class);

    // Cross-tenant isolation: Tenant 2 cannot see Tenant 1 authorization
    ['t' => $t2, 'f' => $f2] = authorizationSetup();
    $tenant2User = insuranceUserWithKeys($t2, $f2, ['insurance.access', 'insurance.authorizations.request']);
    test()->actingAs($tenant2User);

    expect(fn () => queryInsurance($t2, $f2, new GetAuthorization($authId)))
        ->toThrow(AuthorizationNotFound::class);
});
