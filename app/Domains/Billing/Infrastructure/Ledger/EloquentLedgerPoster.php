<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Ledger;

use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The first (and only) writer of `bill_account_ledger_entries` (ADR-187 D-C;
 * ADR-189 D-I; ADR-191 G-1 / G-2). A raw insert confined to Infrastructure/
 * (the `CapabilityReader` precedent) — the append-only ledger row is not an
 * aggregate and emits no event. Runs inside the command transaction;
 * RLS-scoped by the surrounding tenant context.
 *
 * G-1: `entry_type` is validated against the frozen seven-value set.
 * G-2: one fact = at most one ledger row. `post` pre-checks
 * `(tenant_id, source_type, source_id)` and returns silently when the row is
 * already present (a re-run / listener-job retry). The DB `unique` index on
 * the same key is the backstop for a genuinely concurrent double-post — the
 * loser's INSERT raises, aborting its whole command transaction (a clean
 * rollback; the retry then finds the winner's row via this pre-check).
 */
final class EloquentLedgerPoster implements LedgerPoster
{
    public function __construct(private readonly TenantContext $context) {}

    public function post(
        string $financialAccountId,
        string $facilityId,
        string $entryType,
        string $direction,
        string $amount,
        string $currency,
        string $sourceType,
        string $sourceId,
    ): void {
        LedgerEntryType::assert($entryType);

        if (! in_array($direction, ['DEBIT', 'CREDIT'], true)) {
            throw new InvalidArgumentException("Ledger direction [{$direction}] must be DEBIT or CREDIT (ADR-187 D-C).");
        }

        $alreadyPosted = DB::table('bill_account_ledger_entries')
            ->where('tenant_id', $this->context->tenantId())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();

        if ($alreadyPosted) {
            return; // G-2 — this fact already has its ledger row.
        }

        $now = now();

        DB::table('bill_account_ledger_entries')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->tenantId(),
            'facility_id' => $facilityId,
            'financial_account_id' => $financialAccountId,
            'entry_type' => $entryType,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $currency,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'memo_code' => null,
            'posted_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
