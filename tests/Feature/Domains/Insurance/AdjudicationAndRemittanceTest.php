<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\SetInvoiceCoverage;
use App\Domains\Billing\Application\Listener\OnRemittanceLineSettled;
use App\Domains\Billing\Domain\ValueObject\AdjustmentType;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Domains\Insurance\Application\Query\GetAdjudication;
use App\Domains\Insurance\Application\Query\GetClaim;
use App\Domains\Insurance\Application\Query\GetClaimAdjudication;
use App\Domains\Insurance\Application\Query\GetRemittance;
use App\Domains\Insurance\Application\Query\SearchRemittances;
use App\Domains\Insurance\Domain\Exception\AdjudicationArithmeticMismatch;
use App\Domains\Insurance\Domain\Exception\AdjudicationNotFound;
use App\Domains\Insurance\Domain\Exception\ClaimAlreadyAdjudicated;
use App\Domains\Insurance\Domain\Exception\IllegalClaimTransition;
use App\Domains\Insurance\Domain\Exception\RemittanceNotFound;
use App\Domains\Insurance\Domain\ValueObject\AdjudicationLineStatus;
use App\Domains\Insurance\Domain\ValueObject\AdjudicationStatus;
use App\Domains\Insurance\Domain\ValueObject\ClaimDenialReason;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Domains\Insurance\Domain\ValueObject\RemittanceLineStatus;
use App\Domains\Insurance\Domain\ValueObject\RemittanceStatus;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ins.8 — Adjudication & Remittance Processing (ADR-205).
 *
 * @return array{t: string, f: string, a: string, patient: string, account: string, invoice: string, plan: string, payer: string, coverage: string, claimId: string, claimLineId: string, claimedAmount: string}
 */
function adjudicationSetup(): array
{
    $fixture = insuranceFixture([
        'billing.access', 'billing.accounts.manage', 'billing.catalog.manage', 'billing.charges.manage',
        'billing.invoices.manage', 'billing.payments.manage', 'billing.adjustments.request',
        'insurance.access', 'insurance.payers.manage', 'insurance.plans.manage',
        'insurance.members.manage', 'insurance.coverage.manage',
        'insurance.authorizations.request', 'insurance.authorizations.approve',
        'insurance.claims.manage', 'insurance.claims.submit',
        'insurance.adjudications.manage', 'insurance.remittance.record',
    ]);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;

    $category = 'MEDICATION';
    $tariff = '1000.00';
    $qty = '10';

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

    $claimId = insuranceCreateClaimFromInvoice($t, $f, $a, $invoice, primaryDiagnosisCode: 'ICD10:J00');
    insuranceSubmitClaim($t, $f, $a, $claimId);

    $claimSummary = queryInsurance($t, $f, new GetClaim($claimId));
    $claimLineId = $claimSummary->lines[0]->lineId;
    $claimedAmount = $claimSummary->lines[0]->claimedAmount;

    return compact('t', 'f', 'a', 'patient', 'account', 'invoice', 'plan', 'payer', 'coverage', 'claimId', 'claimLineId', 'claimedAmount', 'svc');
}

test('happy path: full claim approval -> adjudication recorded -> status transitions to ADJUDICATED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => $claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
                'remarks' => 'Full approval per standard tariff',
            ],
        ],
        externalReference: 'PAYER-ADJ-999',
        notes: 'Clean claim auto-approved',
    );

    // Verify adjudication query
    $summary = queryInsurance($t, $f, new GetAdjudication($adjId));
    expect($summary->adjudicationId)->toBe($adjId)
        ->and($summary->adjudicationNumber)->toMatch('/^ADJ-\d{8}$/')
        ->and($summary->claimId)->toBe($claimId)
        ->and($summary->status)->toBe(AdjudicationStatus::APPROVED)
        ->and((float) $summary->totalClaimedAmount)->toBe(7200.00)
        ->and((float) $summary->totalApprovedAmount)->toBe(7200.00)
        ->and((float) $summary->totalDeniedAmount)->toBe(0.00)
        ->and((float) $summary->totalPatientResponsibility)->toBe(0.00)
        ->and((float) $summary->totalContractualAdjustment)->toBe(0.00)
        ->and($summary->externalReference)->toBe('PAYER-ADJ-999')
        ->and($summary->lines)->toHaveCount(1)
        ->and($summary->lines[0]->claimLineId)->toBe($claimLineId)
        ->and($summary->lines[0]->status)->toBe(AdjudicationLineStatus::APPROVED);

    // Verify claim status transitioned to ADJUDICATED
    $claim = queryInsurance($t, $f, new GetClaim($claimId));
    expect($claim->status)->toBe(ClaimStatus::ADJUDICATED);

    // Verify GetClaimAdjudication query
    $claimAdj = queryInsurance($t, $f, new GetClaimAdjudication($claimId));
    expect($claimAdj)->not->toBeNull()
        ->and($claimAdj->adjudicationId)->toBe($adjId);
});

test('partial approval with contractual adjustment and patient co-pay', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    // 5000 + 1000 + 200 + 1000 = 7200.00
    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::PARTIALLY_APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => '5000.00',
                'deniedAmount' => '1000.00',
                'patientResponsibility' => '200.00',
                'contractualAdjustment' => '1000.00',
                'denialReasonCode' => ClaimDenialReason::NOT_COVERED,
                'remarks' => 'Partial coverage per contracted plan rule',
            ],
        ],
    );

    $summary = queryInsurance($t, $f, new GetAdjudication($adjId));
    expect($summary->status)->toBe(AdjudicationStatus::PARTIALLY_APPROVED)
        ->and((float) $summary->totalClaimedAmount)->toBe(7200.00)
        ->and((float) $summary->totalApprovedAmount)->toBe(5000.00)
        ->and((float) $summary->totalDeniedAmount)->toBe(1000.00)
        ->and((float) $summary->totalPatientResponsibility)->toBe(200.00)
        ->and((float) $summary->totalContractualAdjustment)->toBe(1000.00)
        ->and($summary->lines[0]->denialReasonCode)->toBe(ClaimDenialReason::NOT_COVERED);

    $claim = queryInsurance($t, $f, new GetClaim($claimId));
    expect($claim->status)->toBe(ClaimStatus::ADJUDICATED);
});

test('denial with standard reason code (§87)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::DENIED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::DENIED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => '0.00',
                'deniedAmount' => $claimedAmount,
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
                'denialReasonCode' => ClaimDenialReason::AUTH_MISSING,
                'remarks' => 'Service requires pre-authorization',
            ],
        ],
    );

    $summary = queryInsurance($t, $f, new GetAdjudication($adjId));
    expect($summary->status)->toBe(AdjudicationStatus::DENIED)
        ->and((float) $summary->totalApprovedAmount)->toBe(0.00)
        ->and((float) $summary->totalDeniedAmount)->toBe(7200.00)
        ->and($summary->lines[0]->denialReasonCode)->toBe(ClaimDenialReason::AUTH_MISSING);

    $claim = queryInsurance($t, $f, new GetClaim($claimId));
    expect($claim->status)->toBe(ClaimStatus::ADJUDICATED);
});

test('technical rejection: status REJECTED transitions claim to REJECTED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::REJECTED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::REJECTED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => '0.00',
                'deniedAmount' => $claimedAmount,
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
                'denialReasonCode' => ClaimDenialReason::CODING_ERROR,
                'remarks' => 'Missing provider taxonomy code',
            ],
        ],
    );

    $summary = queryInsurance($t, $f, new GetAdjudication($adjId));
    expect($summary->status)->toBe(AdjudicationStatus::REJECTED);

    $claim = queryInsurance($t, $f, new GetClaim($claimId));
    expect($claim->status)->toBe(ClaimStatus::REJECTED);
});

test('line arithmetic invariant: claimed != approved + patient_resp + contractual_adj + denied throws AdjudicationArithmeticMismatch', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    // 6000 + 0 + 0 + 0 = 6000 != 7200
    expect(fn () => insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => '6000.00',
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    ))->toThrow(AdjudicationArithmeticMismatch::class);
});

test('lifecycle guards: cannot adjudicate draft claim or already adjudicated claim', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'account' => $account, 'coverage' => $coverage, 'svc' => $svc, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    // 1. Create another claim but do NOT submit it (remains DRAFT)
    $item2 = (string) Str::uuid7();
    billingMapItem($t, $f, $a, $item2, $svc);
    billingRelayDispensed($t, $f, $patient, $item2, '10', $a);
    $draftInvoice = billingCreateInvoice($t, $f, $a, $account);
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $draftInvoice, actorId: $a, memberCoverageId: $coverage));
    billingIssueInvoice($t, $f, $a, $draftInvoice);
    $draftClaimId = insuranceCreateClaimFromInvoice($t, $f, $a, $draftInvoice, primaryDiagnosisCode: 'ICD10:J00');
    $draftClaimSummary = queryInsurance($t, $f, new GetClaim($draftClaimId));

    expect(fn () => insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $draftClaimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $draftClaimSummary->lines[0]->lineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $draftClaimSummary->lines[0]->claimedAmount,
                'approvedAmount' => $draftClaimSummary->lines[0]->claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    ))->toThrow(IllegalClaimTransition::class);

    // 2. Adjudicate the submitted claim successfully
    insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => $claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    );

    // Attempt to adjudicate again -> ClaimAlreadyAdjudicated
    expect(fn () => insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => $claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    ))->toThrow(ClaimAlreadyAdjudicated::class);
});

test('remittance batch recording and search query', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer, 'claimId' => $claimId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    $remId = insuranceRecordRemittance(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        payerId: $payer,
        paymentReference: 'EFT-NHIF-20260301-001',
        totalAmount: '10000.00',
        remittanceDate: '2026-03-01',
        paymentMethod: 'BANK_TRANSFER',
        lines: [
            [
                'claimId' => $claimId,
                'claimedAmount' => $claimedAmount,
                'paidAmount' => $claimedAmount,
                'contractualAdjustment' => '0.00',
                'patientResponsibility' => '0.00',
                'deniedAmount' => '0.00',
            ],
        ],
        notes: 'Monthly batch settlement',
    );

    $summary = queryInsurance($t, $f, new GetRemittance($remId));
    expect($summary->remittanceId)->toBe($remId)
        ->and($summary->remittanceNumber)->toMatch('/^REM-\d{8}$/')
        ->and($summary->payerId)->toBe($payer)
        ->and($summary->status)->toBe(RemittanceStatus::RECORDED)
        ->and((float) $summary->totalAmount)->toBe(10000.00)
        ->and($summary->paymentReference)->toBe('EFT-NHIF-20260301-001')
        ->and($summary->lines)->toHaveCount(1)
        ->and($summary->lines[0]->claimId)->toBe($claimId)
        ->and($summary->lines[0]->status)->toBe(RemittanceLineStatus::UNMATCHED);

    $list = queryInsurance($t, $f, new SearchRemittances(payerId: $payer));
    expect($list)->toHaveCount(1)
        ->and($list[0]->remittanceId)->toBe($remId);
});

test('line reconciliation matching adjudicated claim -> settles payment in billing and marks claim REMITTED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer, 'invoice' => $invoice, 'account' => $account, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    // 1. Adjudicate claim: 6000 approved, 1200 contractual adjustment
    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::PARTIALLY_APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => '6000.00',
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '1200.00',
            ],
        ],
    );

    // 2. Record incoming remittance advice
    $remId = insuranceRecordRemittance(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        payerId: $payer,
        paymentReference: 'EFT-REMIT-7788',
        totalAmount: '6000.00',
        remittanceDate: '2026-03-02',
        paymentMethod: 'BANK_TRANSFER',
        lines: [
            [
                'claimId' => $claimId,
                'adjudicationId' => $adjId,
                'claimedAmount' => $claimedAmount,
                'paidAmount' => '6000.00',
                'contractualAdjustment' => '1200.00',
                'patientResponsibility' => '0.00',
                'deniedAmount' => '0.00',
            ],
        ],
    );

    $remSummary = queryInsurance($t, $f, new GetRemittance($remId));
    $remLineId = $remSummary->lines[0]->lineId;

    // 3. Reconcile line
    insuranceReconcileRemittanceLine(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        remittanceId: $remId,
        remittanceLineId: $remLineId,
        claimId: $claimId,
        paidAmount: '6000.00',
        contractualAdjustment: '1200.00',
    );

    // Verify Insurance status updates
    $reconciledRem = queryInsurance($t, $f, new GetRemittance($remId));
    expect($reconciledRem->status)->toBe(RemittanceStatus::RECONCILED)
        ->and($reconciledRem->lines[0]->status)->toBe(RemittanceLineStatus::MATCHED);

    $remittedClaim = queryInsurance($t, $f, new GetClaim($claimId));
    expect($remittedClaim->status)->toBe(ClaimStatus::REMITTED);

    // 4. Relay outbox event to Billing listener (cross-domain seam)
    test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    billingRunListenerJob(OnRemittanceLineSettled::class);

    // 5. Verify Billing Seam (OnRemittanceLineSettled listener executed!)
    Tenancy::run($t, function () use ($t, $invoice, $account): void {
        // Payment recorded in Billing
        $payment = DB::table('bill_payments')
            ->where('tenant_id', $t)
            ->where('method', PaymentMethod::INSURANCE_REMITTANCE)
            ->first();
        expect($payment)->not->toBeNull()
            ->and((float) $payment->amount)->toBe(6000.00)
            ->and($payment->payment_reference)->toBe('EFT-REMIT-7788');

        // Receipt issued in Billing
        $receipt = DB::table('bill_receipts')
            ->where('payment_id', $payment->id)
            ->first();
        expect($receipt)->not->toBeNull()
            ->and($receipt->receipt_number)->toMatch('/^RCT-\d{8}$/');

        // Allocation created against the invoice
        $allocation = DB::table('bill_payment_allocations')
            ->where('invoice_id', $invoice)
            ->first();
        expect($allocation)->not->toBeNull()
            ->and((float) $allocation->amount)->toBe(6000.00);

        // Contractual allowance adjustment created & applied
        $adjustment = DB::table('bill_adjustments')
            ->where('invoice_id', $invoice)
            ->where('adjustment_type', AdjustmentType::CONTRACTUAL_ALLOWANCE)
            ->first();
        expect($adjustment)->not->toBeNull()
            ->and((float) $adjustment->amount)->toBe(1200.00)
            ->and($adjustment->status)->toBe('APPLIED');

        // Ledger entries verified
        $ledgerEntries = DB::table('bill_account_ledger_entries')
            ->where('financial_account_id', $account)
            ->get();
        $creditTotal = $ledgerEntries->where('direction', 'CREDIT')->sum('amount');
        expect((float) $creditTotal)->toBe(7200.00); // 6000 payment + 1200 adjustment
    });
});

test('underpayment discrepancy auto-detection (§189, §190)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    // Adjudicated for 6000
    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => $claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    );

    // Remittance records only 4000
    $remId = insuranceRecordRemittance(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        payerId: $payer,
        paymentReference: 'EFT-SHORT-001',
        totalAmount: '4000.00',
        remittanceDate: '2026-03-02',
        lines: [
            [
                'claimId' => $claimId,
                'adjudicationId' => $adjId,
                'claimedAmount' => $claimedAmount,
                'paidAmount' => '4000.00',
                'contractualAdjustment' => '0.00',
                'patientResponsibility' => '0.00',
                'deniedAmount' => '0.00',
            ],
        ],
    );

    $remSummary = queryInsurance($t, $f, new GetRemittance($remId));
    $remLineId = $remSummary->lines[0]->lineId;

    // Reconcile line with short payment without explicit reason -> flags UNDERPAYMENT
    insuranceReconcileRemittanceLine(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        remittanceId: $remId,
        remittanceLineId: $remLineId,
        claimId: $claimId,
        paidAmount: '4000.00',
    );

    $reconciledRem = queryInsurance($t, $f, new GetRemittance($remId));
    expect($reconciledRem->status)->toBe(RemittanceStatus::DISCREPANT)
        ->and($reconciledRem->lines[0]->status)->toBe(RemittanceLineStatus::DISCREPANT)
        ->and($reconciledRem->lines[0]->discrepancyReason)->toBe('UNDERPAYMENT');
});

test('RBAC: insurance.adjudications.manage and insurance.remittance.record permissions enforced', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    // User with only insurance.access cannot manage adjudications
    $unprivileged = insuranceUserWithKeys($t, $f, ['insurance.access']);
    test()->actingAs($unprivileged);

    expect(fn () => insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: (string) $unprivileged->id,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => $claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    ))->toThrow(AuthorizationException::class);

    // User with insurance.adjudications.manage can record adjudication
    $adjudicator = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.adjudications.manage']);
    test()->actingAs($adjudicator);

    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: (string) $adjudicator->id,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => $claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    );
    expect($adjId)->not->toBeEmpty();

    // User without remittance permission cannot record remittance
    expect(fn () => insuranceRecordRemittance(
        tenantId: $t,
        facilityId: $f,
        actorId: (string) $adjudicator->id,
        payerId: $payer,
        paymentReference: 'EFT-TEST-01',
        totalAmount: '7200.00',
        remittanceDate: '2026-03-02',
    ))->toThrow(AuthorizationException::class);

    // User with insurance.remittance.record can record remittance
    $remitter = insuranceUserWithKeys($t, $f, ['insurance.access', 'insurance.remittance.record']);
    test()->actingAs($remitter);

    $remId = insuranceRecordRemittance(
        tenantId: $t,
        facilityId: $f,
        actorId: (string) $remitter->id,
        payerId: $payer,
        paymentReference: 'EFT-TEST-01',
        totalAmount: '7200.00',
        remittanceDate: '2026-03-02',
    );
    expect($remId)->not->toBeEmpty();
});

test('multi-tenant isolation: adjudications and remittances cannot be accessed across tenants', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'payer' => $payer, 'claimId' => $claimId, 'claimLineId' => $claimLineId, 'claimedAmount' => $claimedAmount] = adjudicationSetup();

    $adjId = insuranceRecordAdjudication(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        claimId: $claimId,
        status: AdjudicationStatus::APPROVED,
        adjudicationDate: '2026-03-01',
        lines: [
            [
                'claimLineId' => $claimLineId,
                'status' => AdjudicationLineStatus::APPROVED,
                'claimedAmount' => $claimedAmount,
                'approvedAmount' => $claimedAmount,
                'deniedAmount' => '0.00',
                'patientResponsibility' => '0.00',
                'contractualAdjustment' => '0.00',
            ],
        ],
    );

    $remId = insuranceRecordRemittance(
        tenantId: $t,
        facilityId: $f,
        actorId: $a,
        payerId: $payer,
        paymentReference: 'EFT-ISO-01',
        totalAmount: '7200.00',
        remittanceDate: '2026-03-02',
    );

    // Tenant 2 fixture
    $tenant2 = insuranceFixture(['insurance.access', 'insurance.adjudications.manage', 'insurance.remittance.record']);
    $t2 = (string) $tenant2['tenant']->id;
    $f2 = (string) $tenant2['facility']->id;

    expect(fn () => queryInsurance($t2, $f2, new GetAdjudication($adjId)))
        ->toThrow(AdjudicationNotFound::class);

    expect(fn () => queryInsurance($t2, $f2, new GetRemittance($remId)))
        ->toThrow(RemittanceNotFound::class);
});
