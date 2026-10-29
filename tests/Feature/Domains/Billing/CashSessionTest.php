<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\OpenCashSession;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Application\Command\ReconcileCashSession;
use App\Domains\Billing\Application\Command\RecordPayment;
use App\Domains\Billing\Application\Query\GetCashSession;
use App\Domains\Billing\Application\Query\GetFacilityCashSessions;
use App\Domains\Billing\Application\Query\GetPayment;
use App\Domains\Billing\Domain\Exception\CashSessionAlreadyOpen;
use App\Domains\Billing\Domain\Exception\IllegalCashSessionTransition;
use App\Domains\Billing\Domain\Exception\NoCashSessionOpen;
use App\Domains\Billing\Domain\Exception\SelfReconcileNotPermitted;
use App\Domains\Billing\Domain\Model\CashSession;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Bill.6 — cash sessions / till (ADR-195; ADR-191 C6-A…C6-D / G-4).
 *
 * @return array{t: string, f: string, a: string, account: string}
 */
function cashSetup(): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $patient = billingPatient($t);
    $account = (string) Str::uuid7();
    dispatchBilling($t, $f, new OpenFinancialAccount(financialAccountId: $account, actorId: $a, patientId: $patient, facilityId: $f));

    return compact('t', 'f', 'a', 'account');
}

test('OpenCashSession creates an OPEN session with the opening float, cashier = actor', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '150000.00');

    $session = queryBilling($t, $f, new GetCashSession($sessionId));
    expect($session->status)->toBe(CashSession::STATUS_OPEN)
        ->and($session->openingFloat)->toBe('150000.00')
        ->and($session->cashierUserId)->toBe($a)
        ->and($session->expectedCash)->toBe('150000.00') // float, no payments yet
        ->and($session->cashPaymentsTotal)->toBe('0.00');
});

test('one live session per cashier per facility — a second OpenCashSession is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    billingOpenCashSession($t, $f, $a);

    expect(fn () => dispatchBilling($t, $f, new OpenCashSession(
        actorId: $a, cashSessionId: (string) Str::uuid7(), facilityId: $f, openingFloat: '0.00',
    )))->toThrow(CashSessionAlreadyOpen::class);
});

test('two different cashiers may each hold an open session at the same facility (the lock is per-cashier)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    billingOpenCashSession($t, $f, $a);

    $cashier2 = billingUserWithKeys($t, $f, ['billing.access', 'billing.cash_sessions.manage']);
    test()->actingAs($cashier2);
    $s2 = billingOpenCashSession($t, $f, (string) $cashier2->id);

    expect(queryBilling($t, $f, new GetCashSession($s2))->cashierUserId)->toBe((string) $cashier2->id);
    expect(Tenancy::run($t, fn () => DB::table('bill_cash_sessions')->whereIn('status', ['OPEN', 'ACTIVE'])->count()))->toBe(2);
});

test('a CASH payment requires an open cash session (C6-D)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();

    expect(fn () => dispatchBilling($t, $f, new RecordPayment(
        paymentId: (string) Str::uuid7(), actorId: $a, financialAccountId: $account, amount: '1000.00', method: 'CASH',
    )))->toThrow(NoCashSessionOpen::class);
});

test('a CASH payment attaches to the session, flips it OPEN → ACTIVE, and counts toward expectedCash', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '100000.00');

    $paymentId = billingRecordPayment($t, $f, $a, $account, '3000.00');

    expect(queryBilling($t, $f, new GetPayment($paymentId))->cashSessionId)->toBe($sessionId);
    $session = queryBilling($t, $f, new GetCashSession($sessionId));
    expect($session->status)->toBe(CashSession::STATUS_ACTIVE)
        ->and($session->cashPaymentsTotal)->toBe('3000.00')
        ->and($session->expectedCash)->toBe('103000.00');
});

test('a non-CASH payment needs no session; with one open it is attributed but excluded from the cash count (G-4)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();

    // no session — a card payment is fine
    $card = billingRecordPayment($t, $f, $a, $account, '2000.00', 'CARD');
    expect(queryBilling($t, $f, new GetPayment($card))->cashSessionId)->toBeNull();

    // now open a session and take a mobile-money payment
    $sessionId = billingOpenCashSession($t, $f, $a, '0.00');
    $mm = billingRecordPayment($t, $f, $a, $account, '4000.00', 'MOBILE_MONEY');

    expect(queryBilling($t, $f, new GetPayment($mm))->cashSessionId)->toBe($sessionId);
    $session = queryBilling($t, $f, new GetCashSession($sessionId));
    expect($session->cashPaymentsTotal)->toBe('0.00')
        ->and($session->expectedCash)->toBe('0.00')
        ->and($session->nonCashByMethod['MOBILE_MONEY'])->toBe('4000.00');
});

test('CloseCashSession freezes expected / counted / variance; a clean count → zero variance', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '10000.00');
    billingRecordPayment($t, $f, $a, $account, '2500.00'); // expected 12 500

    $ledgerBefore = (int) Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->count());

    billingCloseCashSession($t, $f, $a, $sessionId, '12500.00');

    $session = queryBilling($t, $f, new GetCashSession($sessionId));
    expect($session->status)->toBe(CashSession::STATUS_CLOSED)
        ->and($session->expectedCash)->toBe('12500.00')
        ->and($session->countedCash)->toBe('12500.00')
        ->and($session->variance)->toBe('0.00')
        ->and($session->closedByUserId)->toBe($a);

    // C6-C — the variance is NOT a ledger event
    expect((int) Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->count()))->toBe($ledgerBefore);
});

test('a cash shortage is recorded as a negative variance — never on the ledger (C6-C)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '10000.00');
    billingRecordPayment($t, $f, $a, $account, '2000.00'); // expected 12 000

    billingCloseCashSession($t, $f, $a, $sessionId, '11500.00', 'short by 500');

    $session = queryBilling($t, $f, new GetCashSession($sessionId));
    expect($session->variance)->toBe('-500.00')->and($session->closeNote)->toBe('short by 500');
});

test('after close, a CASH payment by that cashier needs a NEW session (C6-B)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '0.00');
    billingRecordPayment($t, $f, $a, $account, '1000.00');
    billingCloseCashSession($t, $f, $a, $sessionId, '1000.00');

    expect(fn () => dispatchBilling($t, $f, new RecordPayment(
        paymentId: (string) Str::uuid7(), actorId: $a, financialAccountId: $account, amount: '500.00', method: 'CASH',
    )))->toThrow(NoCashSessionOpen::class);

    // opening a fresh one works
    $second = billingOpenCashSession($t, $f, $a, '0.00');
    expect($second)->not->toBe($sessionId);
});

test('CloseCashSession on an already-closed session is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '0.00');
    billingCloseCashSession($t, $f, $a, $sessionId, '0.00');

    expect(fn () => billingCloseCashSession($t, $f, $a, $sessionId, '0.00'))
        ->toThrow(IllegalCashSessionTransition::class);
});

test('ReconcileCashSession: a clean drawer may be self-reconciled by the cashier', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '0.00');
    billingCloseCashSession($t, $f, $a, $sessionId, '0.00');

    billingReconcileCashSession($t, $f, $a, $sessionId);

    $session = queryBilling($t, $f, new GetCashSession($sessionId));
    expect($session->status)->toBe(CashSession::STATUS_RECONCILED)
        ->and($session->reconciledByUserId)->toBe($a)
        ->and($session->varianceApprovedByUserId)->toBeNull();
});

test('ReconcileCashSession: a non-zero variance may NOT be self-reconciled by the cashier; a supervisor may', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '0.00');
    billingRecordPayment($t, $f, $a, $account, '1000.00');
    billingCloseCashSession($t, $f, $a, $sessionId, '900.00'); // variance −100

    expect(fn () => billingReconcileCashSession($t, $f, $a, $sessionId))
        ->toThrow(SelfReconcileNotPermitted::class);

    $supervisor = billingUserWithKeys($t, $f, ['billing.access', 'billing.cash_sessions.reconcile']);
    test()->actingAs($supervisor);
    billingReconcileCashSession($t, $f, (string) $supervisor->id, $sessionId, 'drawer light, approved');

    $session = queryBilling($t, $f, new GetCashSession($sessionId));
    expect($session->status)->toBe(CashSession::STATUS_RECONCILED)
        ->and($session->varianceApprovedByUserId)->toBe((string) $supervisor->id)
        ->and($session->varianceNote)->toBe('drawer light, approved');
});

test('ReconcileCashSession on a non-closed session is refused; idempotent on RECONCILED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '0.00');

    expect(fn () => billingReconcileCashSession($t, $f, $a, $sessionId))
        ->toThrow(IllegalCashSessionTransition::class);

    billingCloseCashSession($t, $f, $a, $sessionId, '0.00');
    billingReconcileCashSession($t, $f, $a, $sessionId);
    billingReconcileCashSession($t, $f, $a, $sessionId); // no-op

    expect(queryBilling($t, $f, new GetCashSession($sessionId))->status)->toBe(CashSession::STATUS_RECONCILED);
});

test('a reversed CASH payment drops out of the session cash count', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '5000.00');
    $paymentId = billingRecordPayment($t, $f, $a, $account, '2000.00');
    expect(queryBilling($t, $f, new GetCashSession($sessionId))->expectedCash)->toBe('7000.00');

    billingReversePayment($t, $f, $a, $paymentId, 'PAYMENT_ERROR');

    expect(queryBilling($t, $f, new GetCashSession($sessionId))->expectedCash)->toBe('5000.00');
});

test('GetFacilityCashSessions lists the facility sessions, newest first', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    $s1 = billingOpenCashSession($t, $f, $a, '0.00');
    billingCloseCashSession($t, $f, $a, $s1, '0.00');
    $s2 = billingOpenCashSession($t, $f, $a, '0.00');

    $list = queryBilling($t, $f, new GetFacilityCashSessions($f, null));
    expect($list)->toHaveCount(2)->and($list[0]->cashSessionId)->toBe($s2);

    $open = queryBilling($t, $f, new GetFacilityCashSessions($f, 'OPEN'));
    expect($open)->toHaveCount(1)->and($open[0]->cashSessionId)->toBe($s2);
});

test('a user without billing.cash_sessions.manage cannot open; without .reconcile cannot reconcile', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = cashSetup();
    $sessionId = billingOpenCashSession($t, $f, $a, '0.00');
    billingCloseCashSession($t, $f, $a, $sessionId, '0.00');

    $clerk = billingUserWithKeys($t, $f, ['billing.access', 'billing.payments.manage']); // no cash-session keys
    test()->actingAs($clerk);

    expect(fn () => dispatchBilling($t, $f, new OpenCashSession(
        actorId: (string) $clerk->id, cashSessionId: (string) Str::uuid7(), facilityId: $f, openingFloat: '0.00',
    )))->toThrow(AuthorizationException::class);

    expect(fn () => dispatchBilling($t, $f, new ReconcileCashSession(actorId: (string) $clerk->id, cashSessionId: $sessionId)))
        ->toThrow(AuthorizationException::class);
});
