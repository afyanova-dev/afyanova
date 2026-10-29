<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — the append-only RFQ award ledger
 * (19-procurement-domain.md §37, §38, §39; ADR-173 D-E).
 *
 * ONE row per (rfq_line, awarded quotation, awarded_qty). **Split award (§38)
 * = ≥ 2 rows for one RFQ line with different `quotation_id`; partial (§39) =
 * Σ `awarded_qty` for a line < the RFQ line quantity** (the shortfall is
 * surfaced in `GetRfq`, NOT auto-re-sourced). The `pcr_po_amendments` /
 * `pcr_po_receipts` pattern — a plain append-only child of `Rfq`, NOT an
 * AggregateRoot; `Rls::enable` + `Rls::enableAppendOnly`; `version` nominal 0.
 *
 * `reason` is free text — ROW-ONLY + audit, NEVER an event (§37 selection
 * reason; the ADR-136 deny-list). `unit_price` is a SNAPSHOT from the quotation
 * line at award time (the PO seeds from it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_rfq_awards', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('rfq_id')->constrained('pcr_rfqs');
            $table->foreignUuid('rfq_line_id')->constrained('pcr_rfq_lines');
            $table->foreignUuid('quotation_id')->constrained('pcr_quotations');
            $table->foreignUuid('quotation_line_id')->constrained('pcr_quotation_lines');
            $table->uuid('supplier_id');                                   // snapshot for the reader

            $table->decimal('awarded_qty', 18, 4);
            $table->decimal('unit_price', 18, 2);                          // snapshot from the quotation line
            $table->text('reason');                                        // ROW-ONLY + audit (§37)

            $table->uuid('decided_by_user_id');
            $table->timestampTz('decided_at');

            $table->unsignedBigInteger('version')->default(0);             // nominal, stays 0
            $table->timestampTz('created_at')->nullable();

            $table->unique(['tenant_id', 'rfq_line_id', 'quotation_id']);
            $table->index(['tenant_id', 'rfq_id']);
        });

        DB::statement('alter table pcr_rfq_awards add constraint pcr_rfq_awards_awarded_qty_positive check (awarded_qty > 0)');

        Rls::enable('pcr_rfq_awards');
        Rls::enableAppendOnly('pcr_rfq_awards');
    }

    public function down(): void
    {
        Rls::disable('pcr_rfq_awards');
        Schema::dropIfExists('pcr_rfq_awards');
    }
};
