<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Application\Command\RetryBillableEvent;
use App\Domains\Billing\Application\Command\SetBillingServiceStatus;
use App\Domains\Billing\Application\Query\GetAccountCharges;
use App\Domains\Billing\Application\Query\GetCharge;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetPendingBillableEvents;
use App\Domains\Billing\Contracts\Event\ChargeCreated;
use App\Domains\Billing\Contracts\Event\ChargeReversed;
use App\Domains\Billing\Domain\Model\Charge;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, svc: string, item: string, patient: string}
 */
function captureSetup(bool $mapped = true, bool $priced = true): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);

    if ($mapped) {
        billingMapItem($t, $f, $a, $item, $svc);
    }
    if ($priced) {
        billingSetTariff($t, $f, $a, $svc, null, '500.00', '2020-01-01');
    }

    return ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item, 'patient' => $patient];
}

function rxBillableEvent(string $t, string $dispensingId): object
{
    return Tenancy::run($t, fn () => DB::table('bill_billable_events')->where('source_ref', $dispensingId)->first());
}

test('a mapped + priced MedicationDispensed captures a POSTED Charge, lazy-opens the account, posts a ledger DEBIT', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item, 'patient' => $patient] = captureSetup();

    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '10', $a);

    $be = rxBillableEvent($t, $d);
    expect($be->status)->toBe('CHARGED')->and($be->charge_id)->not->toBeNull();

    $charge = queryBilling($t, $f, new GetCharge((string) $be->charge_id));
    expect($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and($charge->amount)->toBe('5000.00')          // 500.00 * 10
        ->and($charge->unitPrice)->toBe('500.00')
        ->and($charge->billingServiceId)->toBe($svc)
        ->and($charge->resolvedScope)->toBe('TENANT_DEFAULT')
        ->and($charge->sourceRef)->toBe($d);

    // the account was lazy-opened + ACTIVE, and the balance moved
    $account = Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->first());
    expect($account->status)->toBe('ACTIVE')
        ->and((string) $account->opened_by_user_id)->toBe($a);

    $balance = queryBilling($t, $f, new GetOutstandingBalance((string) $account->id));
    expect($balance->outstanding)->toBe('5000.00')->and($balance->entryCount)->toBe(1);

    $ledger = Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')
        ->where('financial_account_id', $account->id)->first());
    expect($ledger->entry_type)->toBe('CHARGE')->and($ledger->direction)->toBe('DEBIT')
        ->and($ledger->source_type)->toBe('CHARGE');

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', ChargeCreated::class)->first());
    expect(json_decode((string) $event->payload, true)['amount'])->toBe('5000.00');
});

test('an unmapped item is captured PENDING/ITEM_UNMAPPED, and MapItemToBillingService sweeps it into a charge', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item, 'patient' => $patient] = captureSetup(mapped: false);

    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '4', $a);

    $be = rxBillableEvent($t, $d);
    expect($be->status)->toBe('PENDING')->and($be->pending_reason)->toBe('ITEM_UNMAPPED');
    expect(queryBilling($t, $f, new GetPendingBillableEvents))->toHaveCount(1);

    billingMapItem($t, $f, $a, $item, $svc);

    $be = rxBillableEvent($t, $d);
    expect($be->status)->toBe('CHARGED');
    expect(queryBilling($t, $f, new GetPendingBillableEvents))->toHaveCount(0);
    expect(queryBilling($t, $f, new GetCharge((string) $be->charge_id))->amount)->toBe('2000.00'); // 500 * 4
});

test('a mapped but unpriced service is PENDING/SERVICE_UNPRICED; RetryBillableEvent after SetTariff charges it', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item, 'patient' => $patient] = captureSetup(priced: false);

    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '2', $a);
    $be = rxBillableEvent($t, $d);
    expect($be->pending_reason)->toBe('SERVICE_UNPRICED');

    billingSetTariff($t, $f, $a, $svc, null, '750.00', '2020-01-01');
    dispatchBilling($t, $f, new RetryBillableEvent(actorId: $a, billableEventId: (string) $be->id));

    $be = rxBillableEvent($t, $d);
    expect($be->status)->toBe('CHARGED');
    expect(queryBilling($t, $f, new GetCharge((string) $be->charge_id))->amount)->toBe('1500.00');
});

test('a mapped INACTIVE service is PENDING/SERVICE_INACTIVE', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item, 'patient' => $patient] = captureSetup();
    dispatchBilling($t, $f, new SetBillingServiceStatus(billingServiceId: $svc, actorId: $a, status: 'INACTIVE'));

    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '1', $a);
    expect(rxBillableEvent($t, $d)->pending_reason)->toBe('SERVICE_INACTIVE');
});

test('a re-delivered MedicationDispensed creates exactly one charge (idempotent)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'patient' => $patient] = captureSetup();

    $d = (string) Str::uuid7();
    billingRelayDispensed($t, $f, $patient, $item, '3', $a, $d);
    billingRelayDispensed($t, $f, $patient, $item, '3', $a, $d); // same dispensing id, new event id

    expect(Tenancy::run($t, fn () => DB::table('bill_charges')->where('source_ref', $d)->count()))->toBe(1);
    expect(Tenancy::run($t, fn () => DB::table('bill_billable_events')->where('source_ref', $d)->count()))->toBe(1);
});

test('HALF-UP rounding: a 10.01 tariff x 1.5 dispensed = 15.02', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item, 'patient' => $patient] = captureSetup(priced: false);
    billingSetTariff($t, $f, $a, $svc, null, '10.01', '2020-01-01');

    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '1.5', $a);

    $be = rxBillableEvent($t, $d);
    expect(queryBilling($t, $f, new GetCharge((string) $be->charge_id))->amount)->toBe('15.02');
});

test('MedicationDispensingReversed on a CHARGED dispensing reverses the charge + nets the ledger', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'patient' => $patient] = captureSetup();

    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '10', $a);
    $chargeId = (string) rxBillableEvent($t, $d)->charge_id;
    $accountId = queryBilling($t, $f, new GetCharge($chargeId))->financialAccountId;
    expect(queryBilling($t, $f, new GetOutstandingBalance($accountId))->outstanding)->toBe('5000.00');

    billingRelayDispensingReversed($t, $f, $patient, $item, '10', $d, 'WRONG_ITEM');

    expect(rxBillableEvent($t, $d)->status)->toBe('REVERSED');
    $charge = queryBilling($t, $f, new GetCharge($chargeId));
    expect($charge->status)->toBe(Charge::STATUS_REVERSED)->and($charge->reversalId)->not->toBeNull();

    $rev = Tenancy::run($t, fn () => DB::table('bill_charge_reversals')->where('charge_id', $chargeId)->first());
    expect($rev)->not->toBeNull()->and($rev->amount)->toBe('5000.0000')->and($rev->reason_code)->toBe('WRONG_ITEM');

    // ledger: a DEBIT and a CREDIT — net zero. The ADR-191 G-1 breakdown nets
    // the reversal out of chargedTotal and does NOT count it as settlement
    // (this is the fix for the Bill.3 known limitation).
    $balance = queryBilling($t, $f, new GetOutstandingBalance($accountId));
    expect($balance->outstanding)->toBe('0.00')
        ->and($balance->chargedTotal)->toBe('0.00')
        ->and($balance->paidTotal)->toBe('0.00')
        ->and($balance->settledTotal)->toBe('0.00')
        ->and($balance->entryCount)->toBe(2);
    $credit = Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')
        ->where('source_type', 'CHARGE_REVERSAL')->first());
    expect($credit->direction)->toBe('CREDIT')->and($credit->entry_type)->toBe('CHARGE');

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', ChargeReversed::class)->first());
    expect($event)->not->toBeNull();
});

test('MedicationDispensingReversed on a PENDING dispensing cancels the billable event, no charge', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'patient' => $patient] = captureSetup(mapped: false);

    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '5', $a);
    expect(rxBillableEvent($t, $d)->status)->toBe('PENDING');

    billingRelayDispensingReversed($t, $f, $patient, $item, '5', $d);

    expect(rxBillableEvent($t, $d)->status)->toBe('CANCELLED');
    expect(Tenancy::run($t, fn () => DB::table('bill_charges')->where('source_ref', $d)->count()))->toBe(0);
});

test('a pre-existing FinancialAccount is used (not re-opened) for a dispensing charge', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'patient' => $patient] = captureSetup();

    $accountId = (string) Str::uuid7();
    dispatchBilling($t, $f, new OpenFinancialAccount(
        financialAccountId: $accountId, actorId: $a, patientId: $patient, facilityId: $f,
    ));
    billingRelayDispensed($t, $f, $patient, $item, '2', $a);

    expect(Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->count()))->toBe(1);
    expect(queryBilling($t, $f, new GetAccountCharges($accountId)))->toHaveCount(1);
});

test('a user without billing.charges.manage cannot RetryBillableEvent', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'patient' => $patient] = captureSetup(mapped: false);
    ['dispensingId' => $d] = billingRelayDispensed($t, $f, $patient, $item, '1', $a);
    $beId = (string) rxBillableEvent($t, $d)->id;

    $stranger = billingUserWithKeys($t, $f, ['billing.access']);
    test()->actingAs($stranger);

    expect(fn () => dispatchBilling($t, $f, new RetryBillableEvent(actorId: (string) $stranger->id, billableEventId: $beId)))
        ->toThrow(AuthorizationException::class);
});
