<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — the Request for Quotation
 * (19-procurement-domain.md §29–§31, §245; ADR-173 D-B). AggregateRoot, the
 * ADR-132 context+time shape.
 *
 * §31 lifecycle: DRAFT → ISSUED → CLOSED → AWARDED (+ CANCELLED from DRAFT |
 * ISSUED | CLOSED, NOT from AWARDED). OPEN folded into ISSUED, EVALUATING folded
 * into CLOSED (structured evaluation is Proc.2b). A DRAFT RFQ is immutable
 * (cancel + recreate). AWARDED is terminal in Proc.2a.
 *
 * `reason` / `terms` / `cancellation_reason` are free text, ROW-ONLY — NEVER
 * event / audit metadata (the ADR-136 deny-list). `requisition_id` is OPTIONAL
 * (no auto-copy of lines — the ADR-171 PO precedent); when set the requisition
 * must be APPROVED + same facility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_rfqs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('rfq_number');                                   // RFQ-%08d
            $table->foreignUuid('requisition_id')->nullable()->constrained('pcr_purchase_requisitions');
            $table->string('title');
            $table->text('reason')->nullable();                             // ROW-ONLY
            $table->date('required_date')->nullable();
            $table->date('closing_date')->nullable();                       // advisory — CloseRfq is the gate
            $table->text('terms')->nullable();                              // ROW-ONLY

            $table->string('status')->default('DRAFT');                     // §31 — open-coded
            $table->text('cancellation_reason')->nullable();                // ROW-ONLY
            $table->uuid('awarded_by_user_id')->nullable();
            $table->timestampTz('awarded_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'rfq_number']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('pcr_rfqs');
    }

    public function down(): void
    {
        Rls::disable('pcr_rfqs');
        Schema::dropIfExists('pcr_rfqs');
    }
};
