<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — the append-only quotation-revision log
 * (19-procurement-domain.md §233; ADR-173 D-C).
 *
 * One row per pre-close revision of a quotation ("Quotations may be revised
 * before close. Final submitted quotation history should remain traceable").
 * The `pcr_po_amendments` / `pcr_po_receipts` / `inv_stock_movements` pattern
 * EXACTLY: a plain append-only child, NOT an AggregateRoot; `Rls::enable` +
 * `Rls::enableAppendOnly` (RESTRICTIVE — no UPDATE / DELETE; a correction is a
 * further revision row). `version` nominal — stays 0.
 *
 * `reason` is free text — ROW-ONLY + on the audit row, NEVER an event (ADR-136).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_quotation_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('quotation_id')->constrained('pcr_quotations');
            $table->unsignedInteger('sequence');
            $table->string('field_changed');
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');                                        // ROW-ONLY + audit (ADR-136)

            $table->uuid('revised_by_user_id');
            $table->timestampTz('revised_at');

            $table->unsignedBigInteger('version')->default(0);             // nominal, stays 0
            $table->timestampTz('created_at')->nullable();

            $table->unique(['tenant_id', 'quotation_id', 'sequence']);
            $table->index(['tenant_id', 'quotation_id']);
        });

        Rls::enable('pcr_quotation_revisions');
        Rls::enableAppendOnly('pcr_quotation_revisions');
    }

    public function down(): void
    {
        Rls::disable('pcr_quotation_revisions');
        Schema::dropIfExists('pcr_quotation_revisions');
    }
};
