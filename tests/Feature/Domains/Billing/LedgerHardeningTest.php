<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Bill.5a — ledger hardening (ADR-192; ADR-191 G-1 / G-2): per-fact idempotency
 * on `bill_account_ledger_entries`, the frozen `entry_type` set, and the
 * per-`entry_type` `OutstandingBalanceSummary` breakdown.
 *
 * @return array{t: string, f: string, account: string}
 */
function ledgerSetup(): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);
    billingMapItem($t, $f, $a, $item, $svc);
    billingSetTariff($t, $f, $a, $svc, null, '500.00', '2020-01-01');
    billingRelayDispensed($t, $f, $patient, $item, '10', $a); // one CHARGE/DEBIT of 5 000.00

    $account = (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->value('id'));

    return ['t' => $t, 'f' => $f, 'account' => $account];
}

function postLedger(
    string $tenantId,
    string $facilityId,
    string $accountId,
    string $sourceType,
    string $sourceId,
    string $amount = '100.00',
    string $direction = 'CREDIT',
    string $entryType = LedgerEntryType::PAYMENT,
): void {
    TenantContextScope::run($tenantId, $facilityId, fn () => app(LedgerPoster::class)->post(
        financialAccountId: $accountId,
        facilityId: $facilityId,
        entryType: $entryType,
        direction: $direction,
        amount: $amount,
        currency: 'TZS',
        sourceType: $sourceType,
        sourceId: $sourceId,
    ));
}

test('LedgerEntryType is frozen at the seven ADR-187 values', function () {
    expect(LedgerEntryType::ALL)->toHaveCount(7)
        ->and(LedgerEntryType::ALL)->toEqualCanonicalizing(
            ['CHARGE', 'PAYMENT', 'CREDIT', 'DEBIT', 'WRITE_OFF', 'REFUND', 'ADJUSTMENT']
        );
    expect(fn () => LedgerEntryType::assert('SETTLEMENT'))->toThrow(InvalidArgumentException::class);
});

test('LedgerPoster::post is idempotent on (tenant, source_type, source_id) — a re-run writes ONE row', function () {
    ['t' => $t, 'f' => $f, 'account' => $account] = ledgerSetup();
    $sourceId = (string) Str::uuid7();

    postLedger($t, $f, $account, 'PAYMENT', $sourceId, '2500.00');
    postLedger($t, $f, $account, 'PAYMENT', $sourceId, '2500.00'); // same fact, re-delivered
    postLedger($t, $f, $account, 'PAYMENT', $sourceId, '9999.99'); // a different amount is still ignored

    $rows = Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')
        ->where('source_type', 'PAYMENT')->where('source_id', $sourceId)->get());
    expect($rows)->toHaveCount(1)
        ->and((string) $rows->first()->amount)->toBe('2500.0000'); // the first write stands
});

test('the DB unique index rejects a second ledger row for one fact (the G-2 backstop)', function () {
    ['t' => $t, 'f' => $f, 'account' => $account] = ledgerSetup();
    $sourceId = (string) Str::uuid7();
    postLedger($t, $f, $account, 'PAYMENT', $sourceId);

    // bypass LedgerPoster's pre-check — a raw concurrent-style duplicate insert
    expect(fn () => Tenancy::run($t, fn () => DB::table('bill_account_ledger_entries')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $t, 'facility_id' => $f,
        'financial_account_id' => $account, 'entry_type' => 'PAYMENT', 'direction' => 'CREDIT',
        'amount' => '100.00', 'currency' => 'TZS', 'source_type' => 'PAYMENT', 'source_id' => $sourceId,
        'memo_code' => null, 'posted_at' => now(), 'recorded_at' => now(), 'version' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('LedgerPoster::post rejects an entry_type outside the frozen set', function () {
    ['t' => $t, 'f' => $f, 'account' => $account] = ledgerSetup();

    expect(fn () => postLedger($t, $f, $account, 'PAYMENT', (string) Str::uuid7(), '10.00', 'CREDIT', 'SETTLEMENT'))
        ->toThrow(InvalidArgumentException::class);
});

test('LedgerPoster::post rejects a direction that is not DEBIT or CREDIT', function () {
    ['t' => $t, 'f' => $f, 'account' => $account] = ledgerSetup();

    expect(fn () => postLedger($t, $f, $account, 'PAYMENT', (string) Str::uuid7(), '10.00', 'SIDEWAYS'))
        ->toThrow(InvalidArgumentException::class);
});

test('OutstandingBalanceSummary breaks the balance down by entry_type', function () {
    ['t' => $t, 'f' => $f, 'account' => $account] = ledgerSetup(); // 5 000.00 charged, nothing paid

    $before = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($before->chargedTotal)->toBe('5000.00')
        ->and($before->paidTotal)->toBe('0.00')
        ->and($before->writeOffTotal)->toBe('0.00')
        ->and($before->adjustmentTotal)->toBe('0.00')
        ->and($before->settledTotal)->toBe('0.00')
        ->and($before->outstanding)->toBe('5000.00')
        ->and($before->entryCount)->toBe(1);

    postLedger($t, $f, $account, 'PAYMENT', (string) Str::uuid7(), '2000.00'); // a PAYMENT/CREDIT

    $after = queryBilling($t, $f, new GetOutstandingBalance($account));
    expect($after->paidTotal)->toBe('2000.00')
        ->and($after->settledTotal)->toBe('2000.00')
        ->and($after->outstanding)->toBe('3000.00')
        ->and($after->chargedTotal)->toBe('5000.00')  // unchanged — a payment is not a charge
        ->and($after->entryCount)->toBe(2);
});
