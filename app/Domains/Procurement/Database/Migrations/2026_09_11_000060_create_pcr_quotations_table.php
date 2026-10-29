<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — a supplier's quotation against an RFQ
 * (19-procurement-domain.md §32, §33, §233, §246; ADR-173 D-C). AggregateRoot,
 * the ADR-132 context+time shape.
 *
 * ONE quotation per (RFQ, supplier) — `unique(tenant_id, rfq_id, supplier_id)`.
 * Lifecycle: RECEIVED (revisable while the RFQ is ISSUED — §233 append-only
 * `pcr_quotation_revisions`) → SUBMITTED (locked at `CloseRfq`) → AWARDED |
 * NOT_AWARDED (at `AwardRfq`); RECEIVED → WITHDRAWN (while the RFQ is ISSUED).
 * Quotations are PROCUREMENT-STAFF-entered in Proc.2a (`received_by_user_id`); a
 * supplier portal / inbound Integration is §115–§121 (PHASE 12+).
 *
 * `notes` is free text, ROW-ONLY (the ADR-136 deny-list). Prices live on
 * `pcr_quotation_lines`; competing prices are confidential (§101 — read only via
 * `procurement.quotations.view`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_quotations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('quotation_number');                             // QUO-%08d
            $table->foreignUuid('rfq_id')->constrained('pcr_rfqs');
            $table->foreignUuid('supplier_id')->constrained('pcr_suppliers');
            $table->string('supplier_quote_ref')->nullable();              // the supplier's own reference

            $table->string('currency')->default('TZS');                     // single-currency in Proc.2a
            $table->date('validity_date')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('delivery_terms')->nullable();
            $table->text('notes')->nullable();                              // ROW-ONLY

            $table->string('status')->default('RECEIVED');                  // open-coded
            $table->unsignedInteger('revision_count')->default(0);          // dirty-the-row so ReviseQuotation bumps version

            $table->uuid('received_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'quotation_number']);
            $table->unique(['tenant_id', 'rfq_id', 'supplier_id']);
            $table->index(['tenant_id', 'rfq_id', 'status']);
        });

        Rls::enable('pcr_quotations');
    }

    public function down(): void
    {
        Rls::disable('pcr_quotations');
        Schema::dropIfExists('pcr_quotations');
    }
};
