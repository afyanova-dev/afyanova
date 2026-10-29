<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\AccountLedgerEntry;
use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Contracts\Dto\OutstandingBalanceSummary;
use App\Domains\Billing\Domain\FinancialAccountReader;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Money;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent / raw-SQL implementation of {@see FinancialAccountReader}
 * (IC §6.2 / §9; ADR-187 D-E). The ledger table has no aggregate / writer in
 * Bill.1 — `DB::table('bill_account_ledger_entries')` reads are RLS-scoped by
 * the surrounding tenant context.
 */
final class EloquentFinancialAccountReader implements FinancialAccountReader
{
    public function summary(string $financialAccountId): FinancialAccountSummary
    {
        $account = FinancialAccount::query()->whereKey($financialAccountId)->first()
            ?? throw NotFound::aggregate(FinancialAccount::class, $financialAccountId);

        return new FinancialAccountSummary(
            financialAccountId: (string) $account->id,
            accountNumber: (string) $account->account_number,
            facilityId: (string) $account->facility_id,
            patientId: (string) $account->patient_id,
            status: (string) $account->status,
            currency: (string) $account->currency,
            openedAt: $account->opened_at->toIso8601String(),
            openedByUserId: (string) $account->opened_by_user_id,
            closedAt: $account->closed_at?->toIso8601String(),
            closedByUserId: $account->closed_by_user_id !== null ? (string) $account->closed_by_user_id : null,
            closeReasonCode: $account->close_reason_code,
            occurredAt: $account->occurred_at->toIso8601String(),
            recordedAt: $account->recorded_at->toIso8601String(),
        );
    }

    public function exists(string $financialAccountId): bool
    {
        return FinancialAccount::query()->whereKey($financialAccountId)->exists();
    }

    public function ledgerEntries(string $financialAccountId, int $limit): array
    {
        return array_values(DB::table('bill_account_ledger_entries')
            ->where('financial_account_id', $financialAccountId)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(static fn ($r): AccountLedgerEntry => new AccountLedgerEntry(
                entryId: (string) $r->id,
                financialAccountId: (string) $r->financial_account_id,
                facilityId: (string) $r->facility_id,
                entryType: (string) $r->entry_type,
                direction: (string) $r->direction,
                amount: Money::of((string) $r->amount),
                currency: (string) $r->currency,
                sourceType: (string) $r->source_type,
                sourceId: (string) $r->source_id,
                memoCode: $r->memo_code !== null ? (string) $r->memo_code : null,
                postedAt: (string) $r->posted_at,
            ))
            ->all());
    }

    public function outstandingBalance(string $financialAccountId): OutstandingBalanceSummary
    {
        $account = FinancialAccount::query()->whereKey($financialAccountId)->first()
            ?? throw NotFound::aggregate(FinancialAccount::class, $financialAccountId);

        $rows = DB::table('bill_account_ledger_entries')
            ->where('financial_account_id', $financialAccountId)
            ->selectRaw('entry_type, direction, count(*) as n, coalesce(sum(amount), 0) as total')
            ->groupBy('entry_type', 'direction')
            ->get();

        // net(TYPE) = Σ(TYPE, $creditIsPositive ? CREDIT − DEBIT : DEBIT − CREDIT).
        $count = 0;
        /** @var array<string, array{DEBIT: string, CREDIT: string}> $byType */
        $byType = [];
        foreach ($rows as $row) {
            $count += (int) $row->n;
            $type = (string) $row->entry_type;
            $dir = (string) $row->direction;
            $byType[$type] ??= ['DEBIT' => '0', 'CREDIT' => '0'];
            $byType[$type][$dir] = (string) $row->total;
        }

        $net = static function (string $type, bool $creditIsPositive) use ($byType): string {
            $row = $byType[$type] ?? ['DEBIT' => '0', 'CREDIT' => '0'];
            $d = Money::of($row['DEBIT']);
            $c = Money::of($row['CREDIT']);

            return $creditIsPositive ? Money::subtract($c, $d) : Money::subtract($d, $c);
        };

        $chargedTotal = $net('CHARGE', false);
        $paidTotal = $net('PAYMENT', true);
        $writeOffTotal = $net('WRITE_OFF', true);
        $adjustmentTotal = $net('ADJUSTMENT', true);
        $settledTotal = Money::add(Money::add($paidTotal, $writeOffTotal), $adjustmentTotal);

        return new OutstandingBalanceSummary(
            financialAccountId: (string) $account->id,
            currency: (string) $account->currency,
            chargedTotal: $chargedTotal,
            paidTotal: $paidTotal,
            writeOffTotal: $writeOffTotal,
            adjustmentTotal: $adjustmentTotal,
            settledTotal: $settledTotal,
            outstanding: Money::subtract($chargedTotal, $settledTotal),
            entryCount: $count,
        );
    }

    public function activeForPatientFacility(string $patientId, string $facilityId): ?FinancialAccountSummary
    {
        $account = FinancialAccount::query()
            ->where('patient_id', $patientId)
            ->where('facility_id', $facilityId)
            ->where('status', '<>', 'CLOSED')
            ->orderByDesc('recorded_at')
            ->first();

        if ($account === null) {
            return null;
        }

        return $this->summary((string) $account->id);
    }
}
