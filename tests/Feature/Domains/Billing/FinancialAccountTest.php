<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\CloseFinancialAccount;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Application\Query\GetAccountLedger;
use App\Domains\Billing\Application\Query\GetFinancialAccount;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Contracts\Event\FinancialAccountOpened;
use App\Domains\Billing\Domain\Exception\BillingPatientNotFound;
use App\Domains\Billing\Domain\Exception\FinancialAccountAlreadyOpen;
use App\Domains\Billing\Domain\Exception\IllegalFinancialAccountTransition;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, patient: string}
 */
function billingSetup(): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $patient = billingPatient($t);

    return ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient];
}

function openAccount(string $t, string $f, string $a, string $patient): string
{
    $id = (string) Str::uuid7();
    dispatchBilling($t, $f, new OpenFinancialAccount(
        financialAccountId: $id, actorId: $a, patientId: $patient, facilityId: $f,
    ));

    return $id;
}

test('OpenFinancialAccount creates an OPEN account with an FA- number + emits FinancialAccountOpened', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient] = billingSetup();

    $id = openAccount($t, $f, $a, $patient);

    $summary = queryBilling($t, $f, new GetFinancialAccount($id));
    expect($summary->status)->toBe(FinancialAccount::STATUS_OPEN)
        ->and($summary->accountNumber)->toStartWith('FA-')
        ->and($summary->patientId)->toBe($patient)
        ->and($summary->facilityId)->toBe($f)
        ->and($summary->currency)->toBe('TZS')
        ->and($summary->closedAt)->toBeNull();

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', FinancialAccountOpened::class)->first());
    $payload = json_decode((string) $event->payload, true);
    expect($payload['financialAccountId'])->toBe($id)
        ->and($payload['accountNumber'])->toBe($summary->accountNumber)
        ->and($payload)->not->toHaveKey('balance');
});

test('one non-closed account per patient per facility — a second OpenFinancialAccount is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient] = billingSetup();

    openAccount($t, $f, $a, $patient);

    expect(fn () => openAccount($t, $f, $a, $patient))->toThrow(FinancialAccountAlreadyOpen::class);
});

test('the same patient gets a separate account at a different facility', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient] = billingSetup();
    openAccount($t, $f, $a, $patient);

    $otherFacility = (string) Str::uuid7();
    $cashier = billingUserWithKeys($t, $otherFacility, ['billing.access', 'billing.accounts.manage']);
    test()->actingAs($cashier);

    $id2 = (string) Str::uuid7();
    dispatchBilling($t, $otherFacility, new OpenFinancialAccount(
        financialAccountId: $id2, actorId: (string) $cashier->id, patientId: $patient, facilityId: $otherFacility,
    ));

    expect(queryBilling($t, $otherFacility, new GetFinancialAccount($id2))->facilityId)->toBe($otherFacility);
});

test('OpenFinancialAccount for an unknown patient is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = billingSetup();

    expect(fn () => dispatchBilling($t, $f, new OpenFinancialAccount(
        financialAccountId: (string) Str::uuid7(), actorId: $a, patientId: (string) Str::uuid7(), facilityId: $f,
    )))->toThrow(BillingPatientNotFound::class);
});

test('CloseFinancialAccount → CLOSED, and a fresh account may then be opened for the same patient/facility', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient] = billingSetup();
    $id = openAccount($t, $f, $a, $patient);

    dispatchBilling($t, $f, new CloseFinancialAccount(financialAccountId: $id, actorId: $a, reasonCode: 'DUPLICATE_ACCOUNT'));
    expect(queryBilling($t, $f, new GetFinancialAccount($id))->status)->toBe(FinancialAccount::STATUS_CLOSED);

    // CLOSED is terminal
    expect(fn () => dispatchBilling($t, $f, new CloseFinancialAccount(financialAccountId: $id, actorId: $a, reasonCode: 'OTHER')))
        ->toThrow(IllegalFinancialAccountTransition::class);

    // a new account is now allowed (the partial unique index excludes CLOSED)
    $id2 = openAccount($t, $f, $a, $patient);
    expect($id2)->not->toBe($id);
});

test('GetAccountLedger is empty and GetOutstandingBalance is zero in Bill.1 (no charge writer yet)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient] = billingSetup();
    $id = openAccount($t, $f, $a, $patient);

    expect(queryBilling($t, $f, new GetAccountLedger($id)))->toBe([]);

    $balance = queryBilling($t, $f, new GetOutstandingBalance($id));
    expect($balance->outstanding)->toBe('0.00')
        ->and($balance->chargedTotal)->toBe('0.00')
        ->and($balance->paidTotal)->toBe('0.00')
        ->and($balance->writeOffTotal)->toBe('0.00')
        ->and($balance->adjustmentTotal)->toBe('0.00')
        ->and($balance->settledTotal)->toBe('0.00')
        ->and($balance->entryCount)->toBe(0);

    // the zero-outstanding-balance close guard (FinancialAccountNotSettled) is
    // real but unreachable in Bill.1 — no charge writer exists to create a
    // non-zero balance; it is exercised once Bill.3 posts a charge. Here the
    // balance is zero so close succeeds.
    dispatchBilling($t, $f, new CloseFinancialAccount(financialAccountId: $id, actorId: $a, reasonCode: 'ADMIN_CLOSURE'));
    expect(queryBilling($t, $f, new GetFinancialAccount($id))->status)->toBe(FinancialAccount::STATUS_CLOSED);
});

test('a user without billing.accounts.manage cannot open an account; the tables are isolated + append-only', function () {
    ['t' => $t, 'f' => $f] = billingSetup();
    $patient = billingPatient($t);

    $reader = billingUserWithKeys($t, $f, ['billing.access']); // read-only
    test()->actingAs($reader);

    expect(fn () => dispatchBilling($t, $f, new OpenFinancialAccount(
        financialAccountId: (string) Str::uuid7(), actorId: (string) $reader->id, patientId: $patient, facilityId: $f,
    )))->toThrow(AuthorizationException::class);

    $accountPolicies = DB::table('pg_policies')->where('tablename', 'bill_financial_accounts')->pluck('policyname')->all();
    expect($accountPolicies)->toContain('tenant_isolation');

    $ledgerPolicies = DB::table('pg_policies')->where('tablename', 'bill_account_ledger_entries')->pluck('policyname')->all();
    expect($ledgerPolicies)->toContain('tenant_isolation')
        ->and($ledgerPolicies)->toContain('bill_account_ledger_entries_no_update')
        ->and($ledgerPolicies)->toContain('bill_account_ledger_entries_no_delete');
});
