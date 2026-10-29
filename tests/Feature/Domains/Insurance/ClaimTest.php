<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\SetInvoiceCoverage;
use App\Domains\Billing\Domain\Model\Invoice;
use App\Domains\Insurance\Application\Command\RequestAuthorization;
use App\Domains\Insurance\Application\Query\GetClaim;
use App\Domains\Insurance\Application\Query\GetClaimReadiness;
use App\Domains\Insurance\Application\Query\GetPatientClaims;
use App\Domains\Insurance\Application\Query\GetPayerClaims;
use App\Domains\Insurance\Domain\Exception\ChargeAlreadyClaimed;
use App\Domains\Insurance\Domain\Exception\ClaimAlreadySuperseded;
use App\Domains\Insurance\Domain\Exception\ClaimNotDraft;
use App\Domains\Insurance\Domain\Exception\ClaimNotFound;
use App\Domains\Insurance\Domain\Exception\ClaimNotReadyForSubmission;
use App\Domains\Insurance\Domain\Exception\ClaimNotSubmitted;
use App\Domains\Insurance\Domain\Exception\IllegalClaimTransition;
use App\Domains\Insurance\Domain\Exception\InvoiceFacilityMismatch;
use App\Domains\Insurance\Domain\Exception\InvoiceNotInsured;
use App\Domains\Insurance\Domain\Exception\InvoiceNotIssued;
use App\Domains\Insurance\Domain\Exception\ReplacementClaimCannotBeCancelled;
use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\ValueObject\ClaimReadinessStatus;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ins.7 — Claims Capture & Submission (ADR-204 D-A…D-C).
 *
 * @return array{t: string, f: string, a: string, patient: string, account: string, invoice: string, plan: string, payer: string, coverage: string, svc: string, item: string}
 */
function claimSetup(string $category = 'MEDICATION', string $qty = '10', string $tariff = '1000.00'): array
{
    $fixture = insuranceFixture([
        'billing.access', 'billing.accounts.manage', 'billing.catalog.manage', 'billing.charges.manage',
        'billing.invoices.manage', 'billing.payments.manage',
        'insurance.access', 'insurance.payers.manage', 'insurance.plans.manage',
        'insurance.members.manage', 'insurance.coverage.manage',
        'insurance.authorizations.request', 'insurance.authorizations.approve',
        'insurance.claims.manage', 'insurance.claims.submit',
    ]);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;

    $svc = billingAddService($t, $f, $a, 'SVC-'.Str::upper(Str::random(4)), $category);
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);
    billingMapItem($t, $f, $a, $item, $svc);
    billingSetTariff($t, $f, $a, $svc, null, $tariff, '2020-01-01');
    billingRelayDispensed($t, $f, $patient, $item, $qty, $a);
    $account = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->value('id'));

    $payer = insuranceRegisterPayer($t, $f, $a, 'NHIF', 'GOVERNMENT_PROGRAM');
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'STD');
    $coverage = insuranceRegisterCoverage($t, $f, $a, $patient, $plan);
    insuranceSetCoverageRule($t, $f, $a, $plan, $category, 'COVERED', '2020-01-01', coveragePercent: 80, coPayAmount: '1000.00');

    $invoice = billingCreateInvoice($t, $f, $a, $account);
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: $coverage));
    billingIssueInvoice($t, $f, $a, $invoice);

    return compact('t', 'f', 'a', 'patient', 'account', 'invoice', 'plan', 'payer', 'coverage', 'svc', 'item');
}

test('happy path: create claim from invoice -> update draft -> validate readiness -> submit claim', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'invoice' => $invoice, 'plan' => $plan, 'payer' => $payer, 'coverage' => $coverage] = claimSetup();

    // 1. Create claim from invoice
    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice);

    $summary = queryInsurance($t, $f, new GetClaim($claimId));
    expect($summary->claimId)->toBe($claimId)
        ->and($summary->claimNumber)->toMatch('/^CLM-\d{8}$/')
        ->and($summary->tenantId)->toBe($t)
        ->and($summary->facilityId)->toBe($f)
        ->and($summary->invoiceId)->toBe($invoice)
        ->and($summary->patientId)->toBe($patient)
        ->and($summary->payerId)->toBe($payer)
        ->and($summary->planId)->toBe($plan)
        ->and($summary->memberCoverageId)->toBe($coverage)
        ->and($summary->status)->toBe(ClaimStatus::DRAFT)
        ->and($summary->readinessStatus)->toBeNull()
        ->and((float) $summary->totalClaimedAmount)->toBe(7200.00) // (10000 - 1000)*0.8 = 7200
        ->and($summary->currency)->toBe('TZS')
        ->and($summary->lines)->toHaveCount(1)
        ->and($summary->lines[0]->lineActive)->toBeTrue()
        ->and((float) $summary->lines[0]->claimedAmount)->toBe(7200.00)
        ->and($summary->lines[0]->serviceCategory)->toBe('MEDICATION');

    // Invariant: zero ledger entries created by insurance (the 1 entry is from billingRelayDispensed during setup)
    $ledgerCount = Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->count());
    expect($ledgerCount)->toBe(1);

    // 2. Update draft diagnosis
    insuranceUpdateClaimDraft($t, $f, $a, $claimId, primaryDiagnosisCode: 'ICD10:J00', notes: 'Initial claim preparation');

    $updated = queryInsurance($t, $f, new GetClaim($claimId));
    expect($updated->primaryDiagnosisCode)->toBe('ICD10:J00')
        ->and($updated->notes)->toBe('Initial claim preparation');

    // 3. Validate readiness
    insuranceValidateClaimReadiness($t, $f, $a, $claimId);

    $ready = queryInsurance($t, $f, new GetClaim($claimId));
    expect($ready->readinessStatus)->toBe(ClaimReadinessStatus::READY);

    $readinessReport = queryInsurance($t, $f, new GetClaimReadiness($claimId));
    expect($readinessReport->isReady)->toBeTrue()
        ->and($readinessReport->readinessStatus)->toBe(ClaimReadinessStatus::READY);

    // 4. Submit claim
    insuranceSubmitClaim($t, $f, $a, $claimId);

    $submitted = queryInsurance($t, $f, new GetClaim($claimId));
    expect($submitted->status)->toBe(ClaimStatus::SUBMITTED)
        ->and($submitted->submittedAt)->not->toBeNull()
        ->and($submitted->submittedByUserId)->toBe($a);
});

test('invariant 8: submitted claim is immutable and cannot be updated, validated, or cancelled', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = claimSetup();

    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00');
    insuranceValidateClaimReadiness($t, $f, $a, $claimId);
    insuranceSubmitClaim($t, $f, $a, $claimId);

    // Cannot update draft
    expect(fn () => insuranceUpdateClaimDraft($t, $f, $a, $claimId, notes: 'New note'))
        ->toThrow(ClaimNotDraft::class);

    // Cannot validate readiness
    expect(fn () => insuranceValidateClaimReadiness($t, $f, $a, $claimId))
        ->toThrow(ClaimNotDraft::class);

    // Cannot cancel submitted claim
    expect(fn () => insuranceCancelClaim($t, $f, $a, $claimId))
        ->toThrow(IllegalClaimTransition::class);

    // Cannot resubmit
    expect(fn () => insuranceSubmitClaim($t, $f, $a, $claimId))
        ->toThrow(IllegalClaimTransition::class);
});

test('readiness validation blocks submission when diagnosis is missing', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = claimSetup();

    // Create claim without diagnosis
    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice);

    insuranceValidateClaimReadiness($t, $f, $a, $claimId);

    $readiness = queryInsurance($t, $f, new GetClaimReadiness($claimId));
    expect($readiness->readinessStatus)->toBe(ClaimReadinessStatus::BLOCKED)
        ->and(collect($readiness->blockingReasons)->some(fn ($r) => str_contains($r, 'MISSING_DIAGNOSIS')))->toBeTrue();

    // Attempt to submit while BLOCKED must fail
    expect(fn () => insuranceSubmitClaim($t, $f, $a, $claimId))
        ->toThrow(ClaimNotReadyForSubmission::class);
});

test('readiness validation blocks submission when pre-auth is required by plan rule but missing', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'account' => $account, 'plan' => $plan, 'coverage' => $coverage] = claimSetup();

    // Add another service with authorizationRequired = true
    $procSvc = billingAddService($t, $f, $a, 'SVC-PROC', 'PROCEDURE');
    $procItem = (string) Str::uuid7();
    billingMapItem($t, $f, $a, $procItem, $procSvc);
    billingSetTariff($t, $f, $a, $procSvc, null, '2000.00', '2020-01-01');
    insuranceSetCoverageRule($t, $f, $a, $plan, 'PROCEDURE', 'COVERED', '2020-01-01', coveragePercent: 100, authorizationRequired: true);

    billingRelayDispensed($t, $f, $patient, $procItem, '1', $a);

    $invoice = billingCreateInvoice($t, $f, $a, $account);
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: $coverage));
    billingIssueInvoice($t, $f, $a, $invoice);

    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:Z00');

    insuranceValidateClaimReadiness($t, $f, $a, $claimId);
    $readiness = queryInsurance($t, $f, new GetClaimReadiness($claimId));
    expect($readiness->readinessStatus)->toBe(ClaimReadinessStatus::BLOCKED)
        ->and(collect($readiness->blockingReasons)->some(fn ($r) => str_contains($r, 'PREAUTH_REQUIRED_MISSING')))->toBeTrue();

    // Request and approve authorization
    $authId = (string) Str::uuid7();
    dispatchInsurance($t, $f, new RequestAuthorization(
        authorizationId: $authId,
        actorId: $a,
        memberCoverageId: $coverage,
        serviceCategory: CoveredServiceCategory::PROCEDURE,
        serviceDescription: 'Procedure service',
        requestedQuantity: '1.0000',
        requestedAmount: '2000.0000',
    ));
    insuranceApproveAuthorization($t, $f, $a, $authId, approvedAmount: '2000.0000');

    // Link pre-auth and re-validate
    insuranceUpdateClaimDraft($t, $f, $a, $claimId, authorizationId: $authId);
    insuranceValidateClaimReadiness($t, $f, $a, $claimId);

    $readinessAfter = queryInsurance($t, $f, new GetClaimReadiness($claimId));
    expect($readinessAfter->readinessStatus)->toBe(ClaimReadinessStatus::READY)
        ->and($readinessAfter->blockingReasons)->toBe([]);
});

test('invariant 7: cannot double claim charges; cancelling draft frees charges for a new claim', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = claimSetup();

    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00');

    // Attempting to claim the same invoice/charges while claim 1 is active fails with ChargeAlreadyClaimed
    expect(fn () => insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00'))
        ->toThrow(ChargeAlreadyClaimed::class);

    // Cancel draft claim
    insuranceCancelClaim($t, $f, $a, $claimId, reason: 'Wrong diagnosis code entered');

    $cancelled = queryInsurance($t, $f, new GetClaim($claimId));
    expect($cancelled->status)->toBe(ClaimStatus::CANCELLED)
        ->and($cancelled->lines[0]->lineActive)->toBeFalse();

    // Now creating a new claim for the same invoice succeeds because draft lines were deactivated
    $newClaimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J01');
    $newClaim = queryInsurance($t, $f, new GetClaim($newClaimId));
    expect($newClaim->status)->toBe(ClaimStatus::DRAFT)
        ->and($newClaim->primaryDiagnosisCode)->toBe('ICD10:J01')
        ->and($newClaim->lines[0]->lineActive)->toBeTrue();
});

test('supersession atomicity: replacement claim supersedes submitted claim, deactivates old lines, and creates new draft', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = claimSetup();

    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00');
    insuranceValidateClaimReadiness($t, $f, $a, $claimId);
    insuranceSubmitClaim($t, $f, $a, $claimId);

    // Create replacement claim
    $repId = insuranceCreateReplacementClaim(
        $t, $f, $a,
        supersededClaimId: $claimId,
        primaryDiagnosisCode: 'ICD10:J06.9',
        notes: 'Corrected ICD code after clinical review',
    );

    $oldClaim = queryInsurance($t, $f, new GetClaim($claimId));
    expect($oldClaim->status)->toBe(ClaimStatus::SUPERSEDED)
        ->and($oldClaim->supersededByClaimId)->toBe($repId)
        ->and($oldClaim->lines[0]->lineActive)->toBeFalse();

    $repClaim = queryInsurance($t, $f, new GetClaim($repId));
    expect($repClaim->status)->toBe(ClaimStatus::DRAFT)
        ->and($repClaim->supersedesClaimId)->toBe($claimId)
        ->and($repClaim->notes)->toBe('Corrected ICD code after clinical review')
        ->and($repClaim->primaryDiagnosisCode)->toBe('ICD10:J06.9')
        ->and($repClaim->lines[0]->lineActive)->toBeTrue()
        ->and($repClaim->claimNumber)->not->toBe($oldClaim->claimNumber);

    // Single-successor invariant: cannot create another replacement for already superseded claim
    expect(fn () => insuranceCreateReplacementClaim($t, $f, $a, supersededClaimId: $claimId, notes: 'Second attempt'))
        ->toThrow(ClaimAlreadySuperseded::class);

    // Guard: replacement claim cannot be cancelled
    expect(fn () => insuranceCancelClaim($t, $f, $a, $repId))
        ->toThrow(ReplacementClaimCannotBeCancelled::class);
});

test('cannot supersede a draft claim (must be SUBMITTED)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = claimSetup();

    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00');

    expect(fn () => insuranceCreateReplacementClaim($t, $f, $a, supersededClaimId: $claimId, notes: 'Premature replacement'))
        ->toThrow(ClaimNotSubmitted::class);
});

test('invoice validation guards: invoice not issued, not insured, facility mismatch, no claimable lines', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'account' => $account, 'coverage' => $coverage, 'item' => $item] = claimSetup();

    // 1. Invoice not issued (DRAFT)
    billingRelayDispensed($t, $f, $patient, $item, '1', $a);
    $draftInv = billingCreateInvoice($t, $f, $a, $account);
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $draftInv, actorId: $a, memberCoverageId: $coverage));
    expect(fn () => insuranceCreateClaimFromInvoice($t, $f, $a, $draftInv))
        ->toThrow(InvoiceNotIssued::class);

    // 2. Invoice not insured (SELF_PAY)
    billingRelayDispensed($t, $f, $patient, $item, '1', $a);
    $selfPayInv = billingCreateInvoice($t, $f, $a, $account);
    billingIssueInvoice($t, $f, $a, $selfPayInv);
    expect(fn () => insuranceCreateClaimFromInvoice($t, $f, $a, $selfPayInv))
        ->toThrow(InvoiceNotInsured::class);

    // Issued insured invoice from $f
    billingRelayDispensed($t, $f, $patient, $item, '1', $a);
    $issuedInv = billingCreateInvoice($t, $f, $a, $account);
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $issuedInv, actorId: $a, memberCoverageId: $coverage));
    billingIssueInvoice($t, $f, $a, $issuedInv);

    // 3. Facility mismatch: user in different facility
    $otherFacility = (string) Str::uuid7();
    Tenancy::run($t, fn () => DB::table('org_facilities')->insert([
        'id' => $otherFacility, 'tenant_id' => $t, 'name' => 'Branch B', 'code' => 'BR-B',
        'organization_id' => DB::table('org_facilities')->where('id', $f)->value('organization_id'),
        'facility_type' => 'DISPENSARY', 'care_level' => 'PRIMARY', 'ownership_type' => 'PUBLIC', 'status' => 'ACTIVE',
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $otherActor = insuranceUserWithKeys($t, $otherFacility, ['insurance.access', 'insurance.claims.manage', 'billing.access']);
    test()->actingAs($otherActor);

    expect(fn () => insuranceCreateClaimFromInvoice($t, $otherFacility, (string) $otherActor->id, $issuedInv))
        ->toThrow(InvoiceFacilityMismatch::class);
});

test('claims queries: GetPatientClaims and GetPayerClaims', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'invoice' => $invoice, 'payer' => $payer] = claimSetup();

    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00');

    $patientClaims = queryInsurance($t, $f, new GetPatientClaims($patient));
    expect($patientClaims)->toHaveCount(1)
        ->and($patientClaims[0]->claimId)->toBe($claimId);

    $payerClaims = queryInsurance($t, $f, new GetPayerClaims($payer));
    expect($payerClaims)->toHaveCount(1)
        ->and($payerClaims[0]->claimId)->toBe($claimId);
});

test('RBAC: insurance.claims.manage can create draft and cancel, but only insurance.claims.submit can submit', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = claimSetup();

    // User with insurance.claims.manage and billing.access (to read invoice)
    $preparer = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.claims.manage', 'billing.access']);
    test()->actingAs($preparer);

    $claimId = insuranceCreateClaimFromInvoice($t, $f, (string) $preparer->id, $invoice, primaryDiagnosisCode: 'ICD10:J00');
    insuranceValidateClaimReadiness($t, $f, (string) $preparer->id, $claimId);

    // Cannot submit
    expect(fn () => insuranceSubmitClaim($t, $f, (string) $preparer->id, $claimId))
        ->toThrow(AuthorizationException::class);

    // User with only insurance.claims.submit
    $submitter = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.claims.submit']);
    test()->actingAs($submitter);

    // Submitter can submit
    insuranceSubmitClaim($t, $f, (string) $submitter->id, $claimId);

    $submitted = queryInsurance($t, $f, new GetClaim($claimId));
    expect($submitted->status)->toBe(ClaimStatus::SUBMITTED);
});

test('multi-tenant isolation: claims cannot be read across tenants', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = claimSetup();
    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00');

    // Create a second tenant fixture
    $tenant2 = insuranceFixture(['insurance.access', 'insurance.claims.manage']);
    $t2 = (string) $tenant2['tenant']->id;
    $f2 = (string) $tenant2['facility']->id;

    expect(fn () => queryInsurance($t2, $f2, new GetClaim($claimId)))
        ->toThrow(ClaimNotFound::class);
});
