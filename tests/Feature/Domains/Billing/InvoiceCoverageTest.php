<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\AddInvoiceLine;
use App\Domains\Billing\Application\Command\SetInvoiceCoverage;
use App\Domains\Billing\Application\Query\GetInvoice;
use App\Domains\Billing\Domain\Exception\CoverageNotForInvoicePatient;
use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\Model\Invoice;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ins.4 — the Billing estimate seam (ADR-201 D-A…D-E). Crosses Billing +
 * Insurance.
 *
 * @return array{t: string, f: string, a: string, patient: string, account: string, invoice: string, plan: string}
 */
function invoiceCoverageSetup(string $category = 'MEDICATION', string $qty = '20', string $tariff = '500.00'): array
{
    $fixture = billingFixture([
        'billing.access', 'billing.accounts.manage', 'billing.catalog.manage', 'billing.charges.manage',
        'billing.invoices.manage', 'billing.payments.manage',
        'insurance.access', 'insurance.payers.manage', 'insurance.plans.manage',
        'insurance.members.manage', 'insurance.coverage.manage',
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
    insuranceRegisterCoverage($t, $f, $a, $patient, $plan);

    $invoice = billingCreateInvoice($t, $f, $a, $account);

    return compact('t', 'f', 'a', 'patient', 'account', 'invoice', 'plan');
}

test('an invoice with no coverage is SELF_PAY byte-identical (patientResponsibility = net, payer 0)', function () {
    ['t' => $t, 'f' => $f, 'invoice' => $invoice] = invoiceCoverageSetup();

    $inv = queryBilling($t, $f, new GetInvoice($invoice));
    expect($inv->payerType)->toBe(Invoice::PAYER_SELF_PAY)
        ->and($inv->payerId)->toBeNull()
        ->and($inv->net)->toBe('10000.00')
        ->and($inv->patientResponsibility)->toBe('10000.00')      // falls back to net
        ->and($inv->payerResponsibility)->toBe('0.00')
        ->and($inv->derivedPatientOutstanding)->toBe('10000.00')
        ->and($inv->payerResponsibilityOutstanding)->toBe('0.00')
        ->and($inv->derivedOutstanding)->toBe('10000.00')          // unchanged whole-invoice figure
        ->and($inv->lines[0]->payerResponsibility)->toBeNull();
});

test('SetInvoiceCoverage applies an 80% + 2000 co-pay rule (HALF-UP) per line and sums to the invoice; null reverts to SELF_PAY', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice, 'plan' => $plan] = invoiceCoverageSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'MEDICATION', 'COVERED', '2020-01-01', coveragePercent: 80, coPayAmount: '2000.00');
    $mc = (string) Tenancy::run($t, fn () => DB::table('ins_member_coverages')->where('plan_id', $plan)->value('id'));

    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: $mc));

    $inv = queryBilling($t, $f, new GetInvoice($invoice));
    // one line, 10 000: base 8 000, payer round(8000*0.8)=6400, patient 3600
    expect($inv->payerType)->toBe(Invoice::PAYER_INSURANCE)
        ->and($inv->coveragePlanId)->toBe($plan)
        ->and($inv->payerResponsibility)->toBe('6400.00')
        ->and($inv->patientResponsibility)->toBe('3600.00')
        ->and($inv->coverageStatus)->toBe('PARTIALLY_COVERED')
        ->and($inv->coverageSource)->toBe('LOCAL_POLICY')
        ->and($inv->coverageConfidence)->toBe('CONFIRMED')
        ->and($inv->derivedPatientOutstanding)->toBe('3600.00')
        ->and($inv->payerResponsibilityOutstanding)->toBe('6400.00')
        ->and($inv->net)->toBe('10000.00')                          // unchanged
        ->and($inv->derivedOutstanding)->toBe('10000.00')           // unchanged whole-invoice figure
        ->and($inv->lines[0]->payerResponsibility)->toBe('6400.00')
        ->and($inv->lines[0]->patientResponsibility)->toBe('3600.00')
        ->and($inv->lines[0]->serviceCategory)->toBe('MEDICATION');

    // null reverts to SELF_PAY
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: null));
    $inv = queryBilling($t, $f, new GetInvoice($invoice));
    expect($inv->payerType)->toBe(Invoice::PAYER_SELF_PAY)
        ->and($inv->payerId)->toBeNull()
        ->and($inv->payerResponsibility)->toBe('0.00')
        ->and($inv->patientResponsibility)->toBe('10000.00')
        ->and($inv->lines[0]->coverageStatus)->toBeNull();
});

test('no coverage rule -> UNKNOWN, whole amount to the patient (returned as data, not an error)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice, 'plan' => $plan] = invoiceCoverageSetup();
    $mc = (string) Tenancy::run($t, fn () => DB::table('ins_member_coverages')->where('plan_id', $plan)->value('id'));

    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: $mc));

    $inv = queryBilling($t, $f, new GetInvoice($invoice));
    expect($inv->payerType)->toBe(Invoice::PAYER_INSURANCE)
        ->and($inv->coverageStatus)->toBe('UNKNOWN')
        ->and($inv->coverageConfidence)->toBe('UNKNOWN')
        ->and($inv->payerResponsibility)->toBe('0.00')
        ->and($inv->patientResponsibility)->toBe('10000.00');
});

test('coverage cannot be set on an ISSUED invoice; adding a line clears a stale split', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account, 'invoice' => $invoice, 'plan' => $plan] = invoiceCoverageSetup();
    insuranceSetCoverageRule($t, $f, $a, $plan, 'MEDICATION', 'COVERED', '2020-01-01', coveragePercent: 100);
    $mc = (string) Tenancy::run($t, fn () => DB::table('ins_member_coverages')->where('plan_id', $plan)->value('id'));

    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: $mc));
    expect(queryBilling($t, $f, new GetInvoice($invoice))->payerResponsibility)->toBe('10000.00');

    // add a second charge + line -> the stale split clears
    $svc2 = billingAddService($t, $f, $a, 'SVC2-'.Str::upper(Str::random(3)), 'LABORATORY');
    $item2 = (string) Str::uuid7();
    billingMapItem($t, $f, $a, $item2, $svc2);
    billingSetTariff($t, $f, $a, $svc2, null, '1000.00', '2020-01-01');
    billingRelayDispensed($t, $f, queryBilling($t, $f, new GetInvoice($invoice))->patientId, $item2, '3', $a);
    $charge2 = (string) Tenancy::run($t, fn () => DB::table('bill_charges')->where('billing_service_id', $svc2)->value('id'));
    dispatchBilling($t, $f, new AddInvoiceLine(invoiceId: $invoice, actorId: $a, chargeId: $charge2));

    $inv = queryBilling($t, $f, new GetInvoice($invoice));
    expect($inv->payerType)->toBe(Invoice::PAYER_SELF_PAY)
        ->and($inv->net)->toBe('13000.00')
        ->and($inv->patientResponsibility)->toBe('13000.00');

    billingIssueInvoice($t, $f, $a, $invoice);
    expect(fn () => dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: $mc)))
        ->toThrow(IllegalInvoiceTransition::class);
});

test('SetInvoiceCoverage rejects a coverage that is not the invoice patient\'s', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoice' => $invoice] = invoiceCoverageSetup();

    // a coverage for a different patient
    $otherPatient = insurancePatient($t);
    $payer2 = insuranceRegisterPayer($t, $f, $a, 'OTHER');
    $plan2 = insuranceCreatePlan($t, $f, $a, $payer2, 'OTH');
    $otherMc = insuranceRegisterCoverage($t, $f, $a, $otherPatient, $plan2);

    expect(fn () => dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: $a, memberCoverageId: $otherMc)))
        ->toThrow(CoverageNotForInvoicePatient::class);
});

test('a user without insurance.access cannot SetInvoiceCoverage (the InsuranceApi read is protected)', function () {
    ['t' => $t, 'f' => $f, 'invoice' => $invoice, 'plan' => $plan] = invoiceCoverageSetup();
    $mc = (string) Tenancy::run($t, fn () => DB::table('ins_member_coverages')->where('plan_id', $plan)->value('id'));

    $billingOnly = billingUserWithKeys($t, $f, ['billing.access', 'billing.invoices.manage']);
    test()->actingAs($billingOnly);

    expect(fn () => dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoice, actorId: (string) $billingOnly->id, memberCoverageId: $mc)))
        ->toThrow(AuthorizationException::class);
});
