<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\AllocatePayment;
use App\Domains\Billing\Application\Command\VoidInvoice;
use App\Domains\Billing\Application\Query\GetInvoice;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetPayment;
use App\Domains\Billing\Application\Query\GetPaymentAllocations;
use App\Domains\Billing\Domain\Exception\AllocationAccountMismatch;
use App\Domains\Billing\Domain\Exception\AllocationConflict;
use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\Exception\IllegalPaymentTransition;
use App\Domains\Billing\Domain\Exception\InvoiceHasActiveAllocations;
use App\Domains\Billing\Domain\Exception\OverAllocation;
use App\Domains\Billing\Domain\ValueObject\InvoicePaymentStatus;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Bill.5c — payment allocation (ADR-194; ADR-191 B5-A / B5-D / G-5).
 *
 * @return array{t: string, f: string, a: string, item: string, patient: string, account: string, invoiceId: string, paymentId: string}
 */
function allocSetup(string $chargeQty = '10', string $payAmount = '5000.00'): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);
    billingMapItem($t, $f, $a, $item, $svc);
    billingSetTariff($t, $f, $a, $svc, null, '500.00', '2020-01-01'); // 500 * 10 = 5 000.00
    billingRelayDispensed($t, $f, $patient, $item, $chargeQty, $a);
    $account = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->value('id'));
    billingOpenCashSession($t, $f, $a);

    $invoiceId = billingCreateInvoice($t, $f, $a, $account);
    billingIssueInvoice($t, $f, $a, $invoiceId);
    $paymentId = billingRecordPayment($t, $f, $a, $account, $payAmount);

    return compact('t', 'f', 'a', 'item', 'patient', 'account', 'invoiceId', 'paymentId');
}

function allocLedgerCount(string $tenantId, string $accountId): int
{
    return (int) Tenancy::run($tenantId, fn () => DB::table('bill_account_ledger_entries')
        ->where('financial_account_id', $accountId)->count());
}

test('AllocatePayment settles an invoice, posts NO ledger entry, and drives the derived paid-status', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    $ledgerBefore = allocLedgerCount($t, $account); // 1 CHARGE DEBIT + 1 PAYMENT CREDIT = 2

    $allocationId = billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');

    $invoice = queryBilling($t, $f, new GetInvoice($invoiceId));
    expect($invoice->paidAmount)->toBe('5000.00')
        ->and($invoice->paymentStatus)->toBe(InvoicePaymentStatus::PAID)
        ->and($invoice->derivedOutstanding)->toBe('0.00')
        ->and($invoice->net)->toBe('5000.00');

    $payment = queryBilling($t, $f, new GetPayment($paymentId));
    expect($payment->unallocatedAmount)->toBe('0.00');

    // INV-1 analogue — allocation posts NO ledger entry
    expect(allocLedgerCount($t, $account))->toBe($ledgerBefore);

    $allocations = queryBilling($t, $f, new GetPaymentAllocations($paymentId));
    expect($allocations)->toHaveCount(1)
        ->and($allocations[0]->allocationId)->toBe($allocationId)
        ->and($allocations[0]->invoiceId)->toBe($invoiceId)
        ->and($allocations[0]->amount)->toBe('5000.00')
        ->and($allocations[0]->active)->toBeTrue();
});

test('a partial allocation → PARTIALLY_PAID + a reduced derivedOutstanding', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();

    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '2000.00');

    $invoice = queryBilling($t, $f, new GetInvoice($invoiceId));
    expect($invoice->paidAmount)->toBe('2000.00')
        ->and($invoice->paymentStatus)->toBe(InvoicePaymentStatus::PARTIALLY_PAID)
        ->and($invoice->derivedOutstanding)->toBe('3000.00');
});

test('over-allocation past the payment amount is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup(payAmount: '3000.00');

    expect(fn () => billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '4000.00'))
        ->toThrow(OverAllocation::class);
});

test('over-allocation past the invoice net is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup(payAmount: '9000.00');

    expect(fn () => billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '6000.00')) // invoice net is 5 000
        ->toThrow(OverAllocation::class);
});

test('allocating to a DRAFT (un-issued) invoice is refused', function () {
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);
    billingMapItem($t, $f, $a, $item, $svc);
    billingSetTariff($t, $f, $a, $svc, null, '500.00', '2020-01-01');
    billingRelayDispensed($t, $f, $patient, $item, '4', $a);
    $account = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->value('id'));

    $draftInvoice = billingCreateInvoice($t, $f, $a, $account); // DRAFT — not issued
    $paymentId = billingRecordPayment($t, $f, $a, $account, '2000.00');

    expect(fn () => billingAllocatePayment($t, $f, $a, $paymentId, $draftInvoice, '2000.00'))
        ->toThrow(IllegalInvoiceTransition::class);
});

test('allocating a payment and an invoice on different accounts is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'paymentId' => $paymentId] = allocSetup();

    // a second patient → a second account → an issued invoice there
    $patient2 = billingPatient($t);
    $svc2 = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item2 = (string) Str::uuid7();
    billingMapItem($t, $f, $a, $item2, $svc2);
    billingSetTariff($t, $f, $a, $svc2, null, '100.00', '2020-01-01');
    billingRelayDispensed($t, $f, $patient2, $item2, '5', $a);
    $account2 = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient2)->where('facility_id', $f)->value('id'));
    $invoice2 = billingCreateInvoice($t, $f, $a, $account2);
    billingIssueInvoice($t, $f, $a, $invoice2);

    expect(fn () => billingAllocatePayment($t, $f, $a, $paymentId, $invoice2, '100.00'))
        ->toThrow(AllocationAccountMismatch::class);
});

test('allocating a REVERSED payment is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    billingReversePayment($t, $f, $a, $paymentId, 'PAYMENT_ERROR');

    expect(fn () => billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '100.00'))
        ->toThrow(IllegalPaymentTransition::class);
});

test('one live allocation per (payment, invoice) — a second is an AllocationConflict', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '2000.00');

    expect(fn () => billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '1000.00'))
        ->toThrow(AllocationConflict::class);
});

test('UnallocatePayment releases the invoice; the historical row is preserved', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');

    billingUnallocatePayment($t, $f, $a, $paymentId, $invoiceId);

    $invoice = queryBilling($t, $f, new GetInvoice($invoiceId));
    expect($invoice->paidAmount)->toBe('0.00')
        ->and($invoice->paymentStatus)->toBe(InvoicePaymentStatus::UNPAID)
        ->and($invoice->derivedOutstanding)->toBe('5000.00');
    expect(queryBilling($t, $f, new GetPayment($paymentId))->unallocatedAmount)->toBe('5000.00');

    $allocations = queryBilling($t, $f, new GetPaymentAllocations($paymentId));
    expect($allocations)->toHaveCount(1)->and($allocations[0]->active)->toBeFalse(); // preserved, flagged

    // re-allocatable
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');
    expect(queryBilling($t, $f, new GetInvoice($invoiceId))->paymentStatus)->toBe(InvoicePaymentStatus::PAID);
    expect(queryBilling($t, $f, new GetPaymentAllocations($paymentId)))->toHaveCount(2);
});

test('UnallocatePayment is a no-op when there is no live allocation', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();

    billingUnallocatePayment($t, $f, $a, $paymentId, $invoiceId);

    expect(queryBilling($t, $f, new GetPaymentAllocations($paymentId)))->toHaveCount(0);
});

test('AllocatePayment is idempotent on the allocationId', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    $allocationId = (string) Str::uuid7();

    dispatchBilling($t, $f, new AllocatePayment(actorId: $a, allocationId: $allocationId, paymentId: $paymentId, invoiceId: $invoiceId, amount: '2000.00'));
    dispatchBilling($t, $f, new AllocatePayment(actorId: $a, allocationId: $allocationId, paymentId: $paymentId, invoiceId: $invoiceId, amount: '2000.00'));

    expect(queryBilling($t, $f, new GetPaymentAllocations($paymentId)))->toHaveCount(1);
    expect(queryBilling($t, $f, new GetInvoice($invoiceId))->paidAmount)->toBe('2000.00');
});

test('ReversePayment cascade-deactivates the payment allocations, releasing the invoice', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');
    expect(queryBilling($t, $f, new GetInvoice($invoiceId))->paymentStatus)->toBe(InvoicePaymentStatus::PAID);

    billingReversePayment($t, $f, $a, $paymentId, 'BOUNCED');

    $invoice = queryBilling($t, $f, new GetInvoice($invoiceId));
    expect($invoice->paymentStatus)->toBe(InvoicePaymentStatus::UNPAID)
        ->and($invoice->paidAmount)->toBe('0.00')
        ->and($invoice->derivedOutstanding)->toBe('5000.00');

    $allocations = queryBilling($t, $f, new GetPaymentAllocations($paymentId));
    expect($allocations[0]->active)->toBeFalse(); // preserved, flagged

    // the account balance: charge 5 000 − payment 5 000 + reversal 5 000 = 5 000 owed
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('5000.00');
});

test('VoidInvoice refuses an invoice with an active allocation (ADR-191 G-5); unallocate then void succeeds', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');

    expect(fn () => dispatchBilling($t, $f, new VoidInvoice(actorId: $a, invoiceId: $invoiceId, reasonCode: 'BILLING_ERROR')))
        ->toThrow(InvoiceHasActiveAllocations::class);

    billingUnallocatePayment($t, $f, $a, $paymentId, $invoiceId);
    dispatchBilling($t, $f, new VoidInvoice(actorId: $a, invoiceId: $invoiceId, reasonCode: 'BILLING_ERROR'));
    expect(queryBilling($t, $f, new GetInvoice($invoiceId))->status)->toBe('VOIDED');
});

test('a charge reversed AFTER allocation freezes the issued net; the excess is account credit, not a negative invoice (B5-D)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'patient' => $patient, 'account' => $account, 'invoiceId' => $invoiceId, 'paymentId' => $paymentId] = allocSetup();
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00'); // invoice net 5 000, PAID

    // the only charge on the invoice was dispensing #1 (qty 10) — reverse it
    $dispensingId = (string) Tenancy::run($t, fn () => DB::table('bill_billable_events')
        ->where('facility_id', $f)->value('source_ref'));
    billingRelayDispensingReversed($t, $f, $patient, $item, '10', $dispensingId, 'WRONG_ITEM');

    $invoice = queryBilling($t, $f, new GetInvoice($invoiceId));
    expect($invoice->net)->toBe('5000.00')                 // FROZEN
        ->and($invoice->paidAmount)->toBe('5000.00')
        ->and($invoice->derivedOutstanding)->toBe('0.00'); // clamped — not negative

    // the account carries the credit: charge 5 000 − reversal 5 000 − payment 5 000 = −5 000
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('-5000.00');
});
