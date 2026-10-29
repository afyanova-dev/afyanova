<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 10 (increment Bill.5a) — per-fact ledger idempotency
 * (20-billing-and-payments-domain.md §64 / §283; ADR-191 G-2; amends ADR-187
 * D-C by pointer).
 *
 * `bill_account_ledger_entries` is append-only and cannot be corrected, so a
 * double-post of one fact's row is unrecoverable. Bill.3 shipped only the
 * NON-unique `index (tenant_id, source_type, source_id)`; the growing set of
 * Bill.5–7 posting callers (payment, payment-reversal, write-off, adjustment,
 * refund) makes a DB backstop worth its cost. This adds
 * `unique (tenant_id, source_type, source_id)` — one fact (a charge / reversal
 * / payment / write-off / adjustment row) posts AT MOST one ledger row.
 * `LedgerPoster::post` pre-checks the same key (a re-run / listener retry is a
 * no-op); this constraint aborts a genuinely concurrent double-post so the
 * loser's whole command rolls back cleanly.
 *
 * The Bill.3 rows (`CHARGE` / `source_id = charge id`; `CHARGE_REVERSAL` /
 * `source_id = reversal id`, one per charge) carry no duplicates, so the index
 * builds cleanly. Idempotent + faithful `down()` — the `force_rls_on_*`
 * remediation pattern.
 */
return new class extends Migration
{
    private const INDEX = 'bill_account_ledger_entries_fact_unique';

    public function up(): void
    {
        $exists = DB::selectOne(
            'select 1 from pg_indexes where indexname = ?',
            [self::INDEX],
        );

        if ($exists === null) {
            DB::statement(
                'create unique index '.self::INDEX.
                ' on bill_account_ledger_entries (tenant_id, source_type, source_id)'
            );
        }
    }

    public function down(): void
    {
        DB::statement('drop index if exists '.self::INDEX);
    }
};
