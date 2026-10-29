<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2c) — the per-RFQ "requires evaluation" flag
 * (19-procurement-domain.md §23, §37; ADR-175 D-C — **amends ADR-173 D-B by
 * pointer**).
 *
 * ADR-173 D-B's frozen `pcr_rfqs` column list did not carry this. `RequireRfqEvaluation`
 * (`procurement.rfqs.manage`, legal while the RFQ is ISSUED | CLOSED) sets it;
 * `AwardRfqHandler` reads it to HARD-gate the award. NULLABLE — NULL on every
 * pre-Proc.2c row, no backfill; NULL / false = the ADR-174 D-F soft behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_rfqs', function (Blueprint $table): void {
            $table->boolean('requires_evaluation')->nullable()->after('terms');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_rfqs', function (Blueprint $table): void {
            $table->dropColumn('requires_evaluation');
        });
    }
};
