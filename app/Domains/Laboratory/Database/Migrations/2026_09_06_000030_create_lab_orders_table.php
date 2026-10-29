<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory orders (14-laboratory-domain.md §21–§24; ADR-157 D-D). A request
 * to perform laboratory testing. Reuses the ADR-132 context+time shape verbatim
 * (the same pattern proven for Clinical, Bed & Ward, Theatre, Care Coordination).
 *
 * `encounter_id` NOT NULL in Lab.1 (ADR-157 decision — §8's "workflows outside a
 * conventional encounter" is deferred; `facility_id` is the encounter's, the
 * ADR-132 originating-context convention). `patient_id` NOT NULL.
 * `clinical_indication_ref` is a nullable plain uuid with NO FK — the
 * unconstrained-provenance-pointer precedent (CP.1 `source_diagnosis_id`,
 * ADR-151 `clinical_reason_ref`); Laboratory does not constrain which Clinical
 * record justified the order (§16, §27). `clinical_indication_text` /
 * `rejection_reason` / `cancellation_reason` are FREE TEXT, row-only — NEVER in
 * an event or audit metadata (the ADR-136 deny-list).
 *
 * Lab.1 lifecycle is the front of the §24 machine only: DRAFT → ORDERED →
 * ACCEPTED, plus DRAFT|ORDERED → CANCELLED and ORDERED → REJECTED.
 * SPECIMEN_PENDING / PROCESSING / RESULTED / VERIFIED / RELEASED arrive with the
 * specimen (Lab.2) and result (Lab.3) increments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id'); // = the encounter's facility (ADR-132 originating-context convention)

            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->string('order_number'); // tenant-scoped sequential, LAB-%08d

            $table->string('priority')->default('ROUTINE'); // §25 — open-coded, "exact vocabulary is configurable"
            $table->uuid('clinical_indication_ref')->nullable(); // no FK — unconstrained provenance pointer
            $table->text('clinical_indication_text')->nullable(); // free text, row-only (ADR-136)
            $table->text('rejection_reason')->nullable();         // free text, row-only (ADR-136)
            $table->text('cancellation_reason')->nullable();      // free text, row-only (ADR-136)

            $table->string('status')->default('DRAFT');

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'encounter_id']);
        });

        Rls::enable('lab_orders');
    }

    public function down(): void
    {
        Rls::disable('lab_orders');
        Schema::dropIfExists('lab_orders');
    }
};
