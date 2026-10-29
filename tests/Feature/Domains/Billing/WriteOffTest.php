<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\ApproveWriteOff;
use App\Domains\Billing\Application\Command\CloseFinancialAccount;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Application\Command\RequestWriteOff;
use App\Domains\Billing\Application\Query\GetAccountWriteOffs;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetPendingWriteOffs;
use App\Domains\Billing\Application\Query\GetWriteOff;
use App\Domains\Billing\Contracts\Event\WriteOffApplied;
use App\Domains\Billing\Contracts\Event\WriteOffReversed;
use App\Domains\Billing\Domain\Exception\CannotControlClosedAccount;
use App\Domains\Billing\Domain\Exception\IllegalFinancialControlTransition;
use App\Domains\Billing\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Billing\Domain\Model\WriteOff;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Bill.7a — write-offs + the SoD primitive (ADR-196; ADR-191 G-3 / D7-A / D7-B).
 *
 * @return array{t: string, f: string, a: string, patient: string, account: string}
 */
function writeOffSetup(string $chargeQty = '20'): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);
    billingMapItem($t, $f, $a, $item, $svc);
    billingSetTariff($t, $f, $a, $svc, null, '500.00', '2020-01-01'); // 500 * 20 = 10 000.00 owed
    billingRelayDispensed($t, $f, $patient, $item, $chargeQty, $a);
    $account = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->value('id'));

    return compact('t', 'f', 'a', 'patient', 'account');
}

function writeOffLedger(string $tenantId, string $sourceType, string $sourceId): ?object
{
    return Tenancy::run($tenantId, fn () => DB::table('bill_account_ledger_entries')
        ->where('source_type', $sourceType)->where('source_id', $sourceId)->first());
}

test('with no threshold configured every write-off needs dual control — RequestWriteOff lands REQUESTED, posts no ledger', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();

    $woId = billingRequestWriteOff($t, $f, $a, $account, '4000.00', 'APPROVED_BAD_DEBT', note: 'patient untraceable');

    $wo = queryBilling($t, $f, new GetWriteOff($woId));
    expect($wo->status)->toBe(WriteOff::STATUS_REQUESTED)
        ->and($wo->dualControl)->toBeTrue()
        ->and($wo->amount)->toBe('4000.00')
        ->and($wo->note)->toBe('patient untraceable')
        ->and($wo->requestedByUserId)->toBe($a);

    expect(writeOffLedger($t, 'WRITE_OFF', $woId))->toBeNull();
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('10000.00'); // unchanged
});

test('below the per-tenant threshold RequestWriteOff applies immediately (self-approved), posts WRITE_OFF/CREDIT', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    billingConfigureFinancialControl($t, writeOffAbove: '5000.00');

    $woId = billingRequestWriteOff($t, $f, $a, $account, '3000.00');

    $wo = queryBilling($t, $f, new GetWriteOff($woId));
    expect($wo->status)->toBe(WriteOff::STATUS_APPLIED)
        ->and($wo->dualControl)->toBeFalse()
        ->and($wo->approvedByUserId)->toBe($a); // self-approved

    $ledger = writeOffLedger($t, 'WRITE_OFF', $woId);
    expect($ledger)->not->toBeNull()->and($ledger->entry_type)->toBe('WRITE_OFF')->and($ledger->direction)->toBe('CREDIT');

    $balance = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($balance->writeOffTotal)->toBe('3000.00')->and($balance->outstanding)->toBe('7000.00');

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', WriteOffApplied::class)->first());
    expect($event)->not->toBeNull();
});

test('at or above the threshold RequestWriteOff lands REQUESTED and needs a distinct approver', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    billingConfigureFinancialControl($t, writeOffAbove: '5000.00');

    $woId = billingRequestWriteOff($t, $f, $a, $account, '5000.00'); // exactly the threshold → below it (<=)
    expect(queryBilling($t, $f, new GetWriteOff($woId))->status)->toBe(WriteOff::STATUS_APPLIED);

    $woId2 = billingRequestWriteOff($t, $f, $a, $account, '5000.01'); // just over
    expect(queryBilling($t, $f, new GetWriteOff($woId2))->status)->toBe(WriteOff::STATUS_REQUESTED);
});

test('ApproveWriteOff by a distinct approver applies it + posts the ledger; the requester may NOT self-approve', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    $woId = billingRequestWriteOff($t, $f, $a, $account, '6000.00'); // no threshold → REQUESTED

    expect(fn () => billingApproveWriteOff($t, $f, $a, $woId))->toThrow(SelfApprovalNotPermitted::class);

    $approver = billingUserWithKeys($t, $f, ['billing.access', 'billing.writeoffs.approve']);
    test()->actingAs($approver);
    billingApproveWriteOff($t, $f, (string) $approver->id, $woId);

    $wo = queryBilling($t, $f, new GetWriteOff($woId));
    expect($wo->status)->toBe(WriteOff::STATUS_APPLIED)->and($wo->approvedByUserId)->toBe((string) $approver->id);
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('4000.00');
});

test('ApproveWriteOff on an already-APPLIED write-off is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    billingConfigureFinancialControl($t, writeOffAbove: '10000.00');
    $woId = billingRequestWriteOff($t, $f, $a, $account, '2000.00'); // auto-applied

    $approver = billingUserWithKeys($t, $f, ['billing.access', 'billing.writeoffs.approve']);
    test()->actingAs($approver);
    expect(fn () => billingApproveWriteOff($t, $f, (string) $approver->id, $woId))
        ->toThrow(IllegalFinancialControlTransition::class);
});

test('RejectWriteOff → REJECTED, no ledger entry; not legal from APPLIED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    $woId = billingRequestWriteOff($t, $f, $a, $account, '6000.00');

    $approver = billingUserWithKeys($t, $f, ['billing.access', 'billing.writeoffs.approve']);
    test()->actingAs($approver);
    billingRejectWriteOff($t, $f, (string) $approver->id, $woId, 'AMOUNT_INCORRECT');

    $wo = queryBilling($t, $f, new GetWriteOff($woId));
    expect($wo->status)->toBe(WriteOff::STATUS_REJECTED)->and($wo->rejectReasonCode)->toBe('AMOUNT_INCORRECT');
    expect(writeOffLedger($t, 'WRITE_OFF', $woId))->toBeNull();
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('10000.00');
});

test('ReverseWriteOff restores the receivable; the requester may NOT reverse; idempotent', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    billingConfigureFinancialControl($t, writeOffAbove: '10000.00');
    $woId = billingRequestWriteOff($t, $f, $a, $account, '4000.00'); // auto-applied
    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('6000.00');

    expect(fn () => billingReverseWriteOff($t, $f, $a, $woId))->toThrow(SelfApprovalNotPermitted::class);

    $supervisor = billingUserWithKeys($t, $f, ['billing.access', 'billing.writeoffs.approve']);
    test()->actingAs($supervisor);
    billingReverseWriteOff($t, $f, (string) $supervisor->id, $woId, 'SUBSEQUENTLY_COLLECTED');
    billingReverseWriteOff($t, $f, (string) $supervisor->id, $woId); // no-op

    $wo = queryBilling($t, $f, new GetWriteOff($woId));
    expect($wo->status)->toBe(WriteOff::STATUS_REVERSED)->and($wo->reversalId)->not->toBeNull();

    $debit = writeOffLedger($t, 'WRITE_OFF_REVERSAL', (string) $wo->reversalId);
    expect($debit)->not->toBeNull()->and($debit->direction)->toBe('DEBIT');
    expect(Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->where('source_type', 'WRITE_OFF_REVERSAL')->count()))->toBe(1);

    $balance = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($balance->writeOffTotal)->toBe('0.00')->and($balance->outstanding)->toBe('10000.00');
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', WriteOffReversed::class)->count()))->toBe(1);
});

test('ReverseWriteOff is not legal from REQUESTED', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    $woId = billingRequestWriteOff($t, $f, $a, $account, '6000.00'); // REQUESTED

    $supervisor = billingUserWithKeys($t, $f, ['billing.access', 'billing.writeoffs.approve']);
    test()->actingAs($supervisor);
    expect(fn () => billingReverseWriteOff($t, $f, (string) $supervisor->id, $woId))
        ->toThrow(IllegalFinancialControlTransition::class);
});

test('a full write-off brings the account to zero — it may then be closed', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    billingConfigureFinancialControl($t, writeOffAbove: '20000.00');
    billingRequestWriteOff($t, $f, $a, $account, '10000.00'); // the whole balance

    expect(queryBilling($t, $f, new GetOutstandingBalance($account))->outstanding)->toBe('0.00');
    dispatchBilling($t, $f, new CloseFinancialAccount(financialAccountId: $account, actorId: $a, reasonCode: 'ADMIN_CLOSURE'));
    expect(Tenancy::run($t, fn () => DB::table('bill_financial_accounts')->where('id', $account)->value('status')))->toBe('CLOSED');
});

test('RequestWriteOff on a CLOSED account is refused', function () {
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $patient = billingPatient($t);
    $account = (string) Str::uuid7();
    dispatchBilling($t, $f, new OpenFinancialAccount(financialAccountId: $account, actorId: $a, patientId: $patient, facilityId: $f));
    dispatchBilling($t, $f, new CloseFinancialAccount(financialAccountId: $account, actorId: $a, reasonCode: 'DUPLICATE_ACCOUNT'));

    expect(fn () => billingRequestWriteOff($t, $f, $a, $account, '100.00'))->toThrow(CannotControlClosedAccount::class);
});

test('RequestWriteOff is idempotent on the writeOffId', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    billingConfigureFinancialControl($t, writeOffAbove: '10000.00');
    $woId = (string) Str::uuid7();

    dispatchBilling($t, $f, new RequestWriteOff(writeOffId: $woId, actorId: $a, financialAccountId: $account, amount: '1000.00', reasonCode: 'ADMINISTRATIVE'));
    dispatchBilling($t, $f, new RequestWriteOff(writeOffId: $woId, actorId: $a, financialAccountId: $account, amount: '1000.00', reasonCode: 'ADMINISTRATIVE'));

    expect(Tenancy::run($t, fn () => DB::table('bill_write_offs')->where('id', $woId)->count()))->toBe(1);
    expect(Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->where('source_id', $woId)->count()))->toBe(1);
});

test('GetPendingWriteOffs is the approver worklist; GetAccountWriteOffs lists an account\'s write-offs', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    $w1 = billingRequestWriteOff($t, $f, $a, $account, '3000.00'); // REQUESTED (no threshold)
    billingConfigureFinancialControl($t, writeOffAbove: '10000.00');
    billingRequestWriteOff($t, $f, $a, $account, '2000.00'); // APPLIED

    expect(queryBilling($t, $f, new GetPendingWriteOffs))->toHaveCount(1)
        ->and(queryBilling($t, $f, new GetPendingWriteOffs)[0]->writeOffId)->toBe($w1);
    expect(queryBilling($t, $f, new GetAccountWriteOffs($account)))->toHaveCount(2);
});

test('a user without billing.writeoffs.request cannot request; without .approve cannot approve', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'account' => $account] = writeOffSetup();
    $woId = billingRequestWriteOff($t, $f, $a, $account, '6000.00');

    $viewer = billingUserWithKeys($t, $f, ['billing.access']);
    test()->actingAs($viewer);
    expect(fn () => dispatchBilling($t, $f, new RequestWriteOff(writeOffId: (string) Str::uuid7(), actorId: (string) $viewer->id, financialAccountId: $account, amount: '10.00', reasonCode: 'OTHER')))
        ->toThrow(AuthorizationException::class);
    expect(fn () => dispatchBilling($t, $f, new ApproveWriteOff(actorId: (string) $viewer->id, writeOffId: $woId)))
        ->toThrow(AuthorizationException::class);
});

test('billing:configure-financial-control sets and clears the threshold', function () {
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $patient = billingPatient($t);
    $account = (string) Str::uuid7();
    dispatchBilling($t, $f, new OpenFinancialAccount(financialAccountId: $account, actorId: $a, patientId: $patient, facilityId: $f));

    billingConfigureFinancialControl($t, writeOffAbove: '5000.00');
    expect(Tenancy::run($t, fn () => DB::table('bill_financial_control_settings')->where('tenant_id', $t)->value('writeoff_dual_control_above')))->toBe('5000.00');
    $w1 = billingRequestWriteOff($t, $f, $a, $account, '1000.00'); // below → APPLIED
    expect(queryBilling($t, $f, new GetWriteOff($w1))->status)->toBe(WriteOff::STATUS_APPLIED);

    billingConfigureFinancialControl($t, writeOffAbove: ''); // clear → always dual-control
    $w2 = billingRequestWriteOff($t, $f, $a, $account, '1.00');
    expect(queryBilling($t, $f, new GetWriteOff($w2))->status)->toBe(WriteOff::STATUS_REQUESTED);

    test()->artisan('billing:configure-financial-control', ['--writeoff-dual-control-above' => '10'])->assertFailed(); // no --tenant
});
