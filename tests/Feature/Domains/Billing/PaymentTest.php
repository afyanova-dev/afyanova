<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\CloseFinancialAccount;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Application\Command\RecordPayment;
use App\Domains\Billing\Application\Command\ReversePayment;
use App\Domains\Billing\Application\Query\GetAccountPayments;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetPayment;
use App\Domains\Billing\Application\Query\GetReceipt;
use App\Domains\Billing\Contracts\Event\PaymentRecorded;
use App\Domains\Billing\Contracts\Event\PaymentReversed;
use App\Domains\Billing\Domain\Exception\CannotPayClosedAccount;
use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\Model\Receipt;
use App\Domains\Organization\Domain\Model\Facility;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Bill.5b — Payment + Receipt (ADR-193; ADR-191 B5-B / B5-C / G-6 / G-7).
 *
 * @return array{t: string, f: string, a: string, item: string, patient: string, account: string}
 */
function paymentSetup(string $chargeQty = '10'): array
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

    return ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'patient' => $patient, 'account' => $account];
}

function paymentLedger(string $tenantId, string $sourceType, string $sourceId): ?object
{
    return Tenancy::run($tenantId, fn () => DB::table('bill_account_ledger_entries')
        ->where('source_type', $sourceType)->where('source_id', $sourceId)->first());
}

test('RecordPayment posts ONE PAYMENT/CREDIT for the full amount, lowers the balance, mints RCT-00000001', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();

    $paymentId = billingRecordPayment($t, $f, $a, $account, '3000.00');

    $payment = queryBilling($t, $f, new GetPayment($paymentId));
    expect($payment->status)->toBe(Payment::STATUS_RECORDED)
        ->and($payment->amount)->toBe('3000.00')
        ->and($payment->method)->toBe('CASH')
        ->and($payment->unallocatedAmount)->toBe('3000.00')
        ->and($payment->receiptNumber)->toBe('RCT-00000001')
        ->and($payment->collectedByUserId)->toBe($a);

    $ledger = paymentLedger($t, 'PAYMENT', $paymentId);
    expect($ledger)->not->toBeNull()
        ->and($ledger->entry_type)->toBe('PAYMENT')
        ->and($ledger->direction)->toBe('CREDIT')
        ->and((string) $ledger->amount)->toBe('3000.0000')
        ->and((string) $ledger->facility_id)->toBe($f); // G-7 — the account's facility

    $balance = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($balance->chargedTotal)->toBe('5000.00')
        ->and($balance->paidTotal)->toBe('3000.00')
        ->and($balance->settledTotal)->toBe('3000.00')
        ->and($balance->outstanding)->toBe('2000.00');

    $receipt = queryBilling($t, $f, new GetReceipt($paymentId));
    expect($receipt->receiptNumber)->toBe('RCT-00000001')
        ->and($receipt->status)->toBe(Receipt::STATUS_ISSUED)
        ->and($receipt->amount)->toBe('3000.00')
        ->and($receipt->issuedByUserId)->toBe($a);

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', PaymentRecorded::class)->first());
    expect($event)->not->toBeNull();
    expect(json_decode((string) $event->payload, true)['amount'])->toBe('3000.00');
});

test('an overpayment drives the account balance negative (patient credit)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();

    billingRecordPayment($t, $f, $a, $account, '8000.00'); // owed 5 000

    $balance = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($balance->paidTotal)->toBe('8000.00')
        ->and($balance->outstanding)->toBe('-3000.00'); // 3 000 patient credit
});

test('a pure prepayment on an OPEN account activates it and sits as credit', function () {
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $patient = billingPatient($t);

    $account = (string) Str::uuid7();
    dispatchBilling($t, $f, new OpenFinancialAccount(
        financialAccountId: $account, actorId: $a, patientId: $patient, facilityId: $f,
    ));

    billingRecordPayment($t, $f, $a, $account, '20000.00');

    expect(Tenancy::run($t, fn () => DB::table('bill_financial_accounts')->where('id', $account)->value('status')))->toBe('ACTIVE');
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('-20000.00');
});

test('RecordPayment on a CLOSED account is refused', function () {
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $patient = billingPatient($t);
    $account = (string) Str::uuid7();
    dispatchBilling($t, $f, new OpenFinancialAccount(financialAccountId: $account, actorId: $a, patientId: $patient, facilityId: $f));
    dispatchBilling($t, $f, new CloseFinancialAccount(
        financialAccountId: $account, actorId: $a, reasonCode: 'DUPLICATE_ACCOUNT',
    ));

    expect(fn () => billingRecordPayment($t, $f, $a, $account, '1000.00'))
        ->toThrow(CannotPayClosedAccount::class);
});

test('RecordPayment is idempotent on the paymentId — a re-dispatch writes one payment, one ledger row, one receipt', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();
    $paymentId = (string) Str::uuid7();

    dispatchBilling($t, $f, new RecordPayment(paymentId: $paymentId, actorId: $a, financialAccountId: $account, amount: '1000.00', method: 'CASH'));
    dispatchBilling($t, $f, new RecordPayment(paymentId: $paymentId, actorId: $a, financialAccountId: $account, amount: '1000.00', method: 'CASH'));

    expect(Tenancy::run($t, fn () => DB::table('bill_payments')->where('id', $paymentId)->count()))->toBe(1);
    expect(Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->where('source_id', $paymentId)->count()))->toBe(1);
    expect(Tenancy::run($t, fn () => DB::table('bill_receipts')->where('payment_id', $paymentId)->count()))->toBe(1);
});

test('RecordPayment rejects a non-permitted method, while accepting lifted tenders (ADR-191 G-6; ADR-205)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();

    expect(fn () => new RecordPayment(paymentId: (string) Str::uuid7(), actorId: $a, financialAccountId: $account, amount: '10.00', method: 'BITCOIN'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RecordPayment(paymentId: (string) Str::uuid7(), actorId: $a, financialAccountId: $account, amount: '10.00', method: 'CORPORATE_PAYMENT'))
        ->toThrow(InvalidArgumentException::class);

    // ADR-205 lifted the G-6 fence for INSURANCE_REMITTANCE
    $cmd = new RecordPayment(paymentId: (string) Str::uuid7(), actorId: $a, financialAccountId: $account, amount: '10.00', method: 'INSURANCE_REMITTANCE');
    expect($cmd->method)->toBe('INSURANCE_REMITTANCE');
});

test('RecordPayment rejects a non-positive amount', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();

    expect(fn () => new RecordPayment(paymentId: (string) Str::uuid7(), actorId: $a, financialAccountId: $account, amount: '0.00', method: 'CASH'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RecordPayment(paymentId: (string) Str::uuid7(), actorId: $a, financialAccountId: $account, amount: '-5.00', method: 'CASH'))
        ->toThrow(InvalidArgumentException::class);
});

test('ReversePayment → REVERSED, a compensating PAYMENT/DEBIT, the balance restored, the receipt flagged (not deleted)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();
    $paymentId = billingRecordPayment($t, $f, $a, $account, '3000.00');
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('2000.00');

    billingReversePayment($t, $f, $a, $paymentId, 'WRONG_ACCOUNT');

    $payment = queryBilling($t, $f, new GetPayment($paymentId));
    expect($payment->status)->toBe(Payment::STATUS_REVERSED)
        ->and($payment->reversalId)->not->toBeNull()
        ->and($payment->reverseReasonCode)->toBe('WRONG_ACCOUNT');

    // the compensating DEBIT nets the payment to zero
    $debit = paymentLedger($t, 'PAYMENT_REVERSAL', (string) $payment->reversalId);
    expect($debit)->not->toBeNull()->and($debit->direction)->toBe('DEBIT')->and($debit->entry_type)->toBe('PAYMENT');

    $balance = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($balance->paidTotal)->toBe('0.00')->and($balance->outstanding)->toBe('5000.00');

    // §94 — receipt preserved + flagged
    $receipt = queryBilling($t, $f, new GetReceipt($paymentId));
    expect($receipt->status)->toBe(Receipt::STATUS_REVERSED)->and($receipt->reversedAt)->not->toBeNull();
    expect(Tenancy::run($t, fn () => DB::table('bill_receipts')->where('payment_id', $paymentId)->count()))->toBe(1);

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', PaymentReversed::class)->first());
    expect($event)->not->toBeNull();
});

test('ReversePayment is idempotent — a second reverse is a no-op', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();
    $paymentId = billingRecordPayment($t, $f, $a, $account, '3000.00');

    billingReversePayment($t, $f, $a, $paymentId);
    billingReversePayment($t, $f, $a, $paymentId);

    expect(Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')
        ->where('source_type', 'PAYMENT_REVERSAL')->count()))->toBe(1);
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('5000.00');
});

test('a payment collected at another facility posts the ledger entry to the account owning facility (G-7)', function () {
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $fA = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $org = (string) $fixture['organization']->id;
    $svc = billingAddService($t, $fA, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);
    billingMapItem($t, $fA, $a, $item, $svc);
    billingSetTariff($t, $fA, $a, $svc, null, '500.00', '2020-01-01');
    billingRelayDispensed($t, $fA, $patient, $item, '10', $a); // account lazy-opened at facility A
    $account = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $fA)->value('id'));

    $facilityB = (string) TenantContextScope::run($t, null, fn () => Facility::factory()
        ->forTenant($t, $org)->create()->id);
    $cashierB = billingUserWithKeys($t, $facilityB, ['billing.access', 'billing.payments.manage', 'billing.cash_sessions.manage']);
    test()->actingAs($cashierB);

    $paymentId = billingRecordPayment($t, $facilityB, (string) $cashierB->id, $account, '1000.00');

    $payment = queryBilling($t, $facilityB, new GetPayment($paymentId));
    expect($payment->facilityId)->toBe($fA)                       // financial truth (G-7)
        ->and($payment->collectedAtFacilityId)->toBe($facilityB); // collection point

    expect((string) paymentLedger($t, 'PAYMENT', $paymentId)->facility_id)->toBe($fA);
});

test('GetAccountPayments returns the account payments newest first', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();
    $p1 = billingRecordPayment($t, $f, $a, $account, '1000.00');
    $p2 = billingRecordPayment($t, $f, $a, $account, '2000.00');

    $list = queryBilling($t, $f, new GetAccountPayments($account));
    expect($list)->toHaveCount(2)
        ->and($list[0]->paymentId)->toBe($p2)
        ->and($list[1]->paymentId)->toBe($p1);
});

test('a user without billing.payments.manage cannot RecordPayment; without .reverse cannot ReversePayment', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = paymentSetup();
    $paymentId = billingRecordPayment($t, $f, $a, $account, '1000.00');

    $recorder = billingUserWithKeys($t, $f, ['billing.access', 'billing.payments.manage']); // no .reverse
    test()->actingAs($recorder);
    expect(fn () => dispatchBilling($t, $f, new ReversePayment(actorId: (string) $recorder->id, paymentId: $paymentId, reasonCode: 'OTHER')))
        ->toThrow(AuthorizationException::class);

    $reverser = billingUserWithKeys($t, $f, ['billing.access', 'billing.payments.reverse']); // no .manage
    test()->actingAs($reverser);
    expect(fn () => dispatchBilling($t, $f, new RecordPayment(paymentId: (string) Str::uuid7(), actorId: (string) $reverser->id, financialAccountId: $account, amount: '10.00', method: 'CASH')))
        ->toThrow(AuthorizationException::class);
});
