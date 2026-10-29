<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\ApproveAdjustment;
use App\Domains\Billing\Application\Command\RequestAdjustment;
use App\Domains\Billing\Application\Query\GetAccountAdjustments;
use App\Domains\Billing\Application\Query\GetAdjustment;
use App\Domains\Billing\Application\Query\GetInvoice;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetPendingAdjustments;
use App\Domains\Billing\Contracts\Event\AdjustmentApplied;
use App\Domains\Billing\Contracts\Event\AdjustmentReversed;
use App\Domains\Billing\Domain\Exception\IllegalFinancialControlTransition;
use App\Domains\Billing\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Billing\Domain\Model\Adjustment;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Bill.7b — adjustments (ADR-197; ADR-191 G-3 / D7-D). Reuses the Bill.7a SoD
 * primitive verbatim.
 *
 * @return array{t: string, f: string, a: string, patient: string, account: string}
 */
function adjustmentSetup(string $chargeQty = '20'): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);
    billingMapItem($t, $f, $a, $item, $svc);
    billingSetTariff($t, $f, $a, $svc, null, '500.00', '2020-01-01'); // 10 000.00 owed
    billingRelayDispensed($t, $f, $patient, $item, $chargeQty, $a);
    $account = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->value('id'));

    return compact('t', 'f', 'a', 'patient', 'account');
}

test('a below-threshold CREDIT adjustment (GOODWILL) self-applies + posts ADJUSTMENT/CREDIT, lowering the balance', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    billingConfigureFinancialControl($t, adjustmentAbove: '5000.00');

    $adjId = billingRequestAdjustment($t, $f, $a, $account, '1500.00', 'GOODWILL', note: 'service recovery');

    $adj = queryBilling($t, $f, new GetAdjustment($adjId));
    expect($adj->status)->toBe(Adjustment::STATUS_APPLIED)
        ->and($adj->direction)->toBe('CREDIT')
        ->and($adj->adjustmentType)->toBe('GOODWILL')
        ->and($adj->approvedByUserId)->toBe($a)
        ->and($adj->dualControl)->toBeFalse();

    $ledger = Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->where('source_id', $adjId)->first());
    expect($ledger->entry_type)->toBe('ADJUSTMENT')->and($ledger->direction)->toBe('CREDIT');

    $balance = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($balance->adjustmentTotal)->toBe('1500.00')->and($balance->outstanding)->toBe('8500.00');

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', AdjustmentApplied::class)->first());
    expect($event)->not->toBeNull();
});

test('an ADMIN_DEBIT adjustment raises the balance (§138 debit note)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    billingConfigureFinancialControl($t, adjustmentAbove: '5000.00');

    $adjId = billingRequestAdjustment($t, $f, $a, $account, '2000.00', 'ADMIN_DEBIT');

    expect(queryBilling($t, $f, new GetAdjustment($adjId))->direction)->toBe('DEBIT');
    $balance = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($balance->adjustmentTotal)->toBe('-2000.00')->and($balance->outstanding)->toBe('12000.00');
});

test('above the threshold an adjustment needs a distinct approver; the requester may NOT self-approve', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    $adjId = billingRequestAdjustment($t, $f, $a, $account, '3000.00', 'ADMIN_CREDIT'); // no threshold → REQUESTED
    expect(queryBilling($t, $f, new GetAdjustment($adjId))->status)->toBe(Adjustment::STATUS_REQUESTED);

    expect(fn () => billingApproveAdjustment($t, $f, $a, $adjId))->toThrow(SelfApprovalNotPermitted::class);

    $approver = billingUserWithKeys($t, $f, ['billing.access', 'billing.adjustments.approve']);
    test()->actingAs($approver);
    billingApproveAdjustment($t, $f, (string) $approver->id, $adjId);

    expect(queryBilling($t, $f, new GetAdjustment($adjId))->status)->toBe(Adjustment::STATUS_APPLIED);
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('7000.00');
});

test('RejectAdjustment → REJECTED, no ledger; ReverseAdjustment posts the OPPOSITE direction', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    billingConfigureFinancialControl($t, adjustmentAbove: '10000.00');

    $rejected = billingRequestAdjustment($t, $f, $a, $account, '3000.00', 'ADMIN_CREDIT');
    // wait — that auto-applied (below 10 000). Reject needs REQUESTED:
    $req = billingRequestAdjustment($t, $f, $a, $account, '20000.00', 'ADMIN_CREDIT'); // above → REQUESTED
    $approver = billingUserWithKeys($t, $f, ['billing.access', 'billing.adjustments.approve']);
    test()->actingAs($approver);
    billingRejectAdjustment($t, $f, (string) $approver->id, $req, 'AMOUNT_INCORRECT');
    expect(queryBilling($t, $f, new GetAdjustment($req))->status)->toBe(Adjustment::STATUS_REJECTED);
    expect(Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->where('source_id', $req)->count()))->toBe(0);

    // reverse the applied one
    billingReverseAdjustment($t, $f, (string) $approver->id, $rejected, 'RAISED_IN_ERROR');
    $adj = queryBilling($t, $f, new GetAdjustment($rejected));
    expect($adj->status)->toBe(Adjustment::STATUS_REVERSED);
    $debit = Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')
        ->where('source_type', 'ADJUSTMENT_REVERSAL')->where('source_id', $adj->reversalId)->first());
    expect($debit->direction)->toBe('DEBIT'); // opposite of the CREDIT it undoes
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->adjustmentTotal)->toBe('0.00');
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', AdjustmentReversed::class)->count()))->toBe(1);
});

test('ReverseAdjustment is idempotent and not legal from REQUESTED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    $req = billingRequestAdjustment($t, $f, $a, $account, '3000.00', 'GOODWILL'); // REQUESTED

    $approver = billingUserWithKeys($t, $f, ['billing.access', 'billing.adjustments.approve']);
    test()->actingAs($approver);
    expect(fn () => billingReverseAdjustment($t, $f, (string) $approver->id, $req))
        ->toThrow(IllegalFinancialControlTransition::class);

    billingApproveAdjustment($t, $f, (string) $approver->id, $req);
    billingReverseAdjustment($t, $f, (string) $approver->id, $req);
    billingReverseAdjustment($t, $f, (string) $approver->id, $req); // no-op
    expect(Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->where('source_type', 'ADJUSTMENT_REVERSAL')->count()))->toBe(1);
});

test('GetInvoice reflects an APPLIED adjustment / write-off tagged to it in derivedOutstanding', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    billingConfigureFinancialControl($t, writeOffAbove: '20000.00', adjustmentAbove: '20000.00');
    $invoiceId = billingCreateInvoice($t, $f, $a, $account);
    billingIssueInvoice($t, $f, $a, $invoiceId); // net 10 000

    billingRequestAdjustment($t, $f, $a, $account, '1000.00', 'GOODWILL', invoiceId: $invoiceId);
    billingRequestWriteOff($t, $f, $a, $account, '2000.00', 'CONTRACTUAL', invoiceId: $invoiceId);

    $invoice = queryBilling($t, $f, new GetInvoice($invoiceId));
    expect($invoice->net)->toBe('10000.00')
        ->and($invoice->adjustmentAmount)->toBe('1000.00')
        ->and($invoice->writeOffAmount)->toBe('2000.00')
        ->and($invoice->derivedOutstanding)->toBe('7000.00'); // 10 000 − 1 000 − 2 000
});

test('the pending / account adjustment lists', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    $req = billingRequestAdjustment($t, $f, $a, $account, '3000.00', 'GOODWILL'); // REQUESTED
    billingConfigureFinancialControl($t, adjustmentAbove: '10000.00');
    billingRequestAdjustment($t, $f, $a, $account, '500.00', 'ROUNDING'); // APPLIED

    expect(queryBilling($t, $f, new GetPendingAdjustments))->toHaveCount(1)
        ->and(queryBilling($t, $f, new GetPendingAdjustments)[0]->adjustmentId)->toBe($req);
    expect(queryBilling($t, $f, new GetAccountAdjustments($account)))->toHaveCount(2);
});

test('a user without billing.adjustments.request cannot request; without .approve cannot approve', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = adjustmentSetup();
    $adjId = billingRequestAdjustment($t, $f, $a, $account, '3000.00', 'GOODWILL');

    $viewer = billingUserWithKeys($t, $f, ['billing.access']);
    test()->actingAs($viewer);
    expect(fn () => dispatchBilling($t, $f, new RequestAdjustment(adjustmentId: (string) Str::uuid7(), actorId: (string) $viewer->id, financialAccountId: $account, amount: '10.00', adjustmentType: 'OTHER')))
        ->toThrow(AuthorizationException::class);
    expect(fn () => dispatchBilling($t, $f, new ApproveAdjustment(actorId: (string) $viewer->id, adjustmentId: $adjId)))
        ->toThrow(AuthorizationException::class);
});
