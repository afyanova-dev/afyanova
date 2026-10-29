<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remediation — ADR-160 D-D amended by pointer (2026-09-07).
 *
 * `2026_09_06_000080_create_lab_result_amendments_table` shipped (PHASE 8 /
 * Lab.5) calling `Rls::enableAppendOnly('lab_result_amendments')` WITHOUT the
 * preceding `Rls::enable(...)`. Result: the table carried the RESTRICTIVE
 * no-UPDATE / no-DELETE policies but NO `tenant_isolation` policy, and RLS was
 * never `ENABLE`d / `FORCE`d — so there was no DB-level tenant isolation on a
 * PHI-bearing clinical table (before/after result values + free-text `reason`),
 * and the append-only policies were dormant (RESTRICTIVE policies are not
 * enforced while RLS is off). Every other append-only table calls BOTH
 * (`thr_operative_note_amendments`, `pcr_po_amendments`, `inv_stock_movements`).
 *
 * This adds the missing `tenant_isolation` policy + `ENABLE` + `FORCE`.
 * Idempotent — skipped when the policy is already present (a fresh DB whose
 * create migration is later corrected, or a re-run).
 */
return new class extends Migration
{
    private const TABLE = 'lab_result_amendments';

    public function up(): void
    {
        $alreadyIsolated = DB::selectOne(
            "select 1 from pg_policies where tablename = ? and policyname = 'tenant_isolation'",
            [self::TABLE],
        );

        if ($alreadyIsolated === null) {
            Rls::enable(self::TABLE);
        }
    }

    public function down(): void
    {
        // Faithful inverse: back to the (buggy) post-`enableAppendOnly` state —
        // the RESTRICTIVE policies stay, RLS goes off.
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON '.self::TABLE);
        DB::statement('ALTER TABLE '.self::TABLE.' NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE '.self::TABLE.' DISABLE ROW LEVEL SECURITY');
    }
};
