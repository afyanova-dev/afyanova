<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imaging orders (15-radiology-domain.md §18–§21; ADR-165 D-E). A request for
 * an imaging examination. Reuses the ADR-132 context+time shape verbatim (the
 * Lab.1 `lab_orders` pattern, itself the Clinical / Bed & Ward / Theatre / Care
 * Coordination pattern).
 *
 * `encounter_id` NOT NULL in Rad.1 (ADR-165 decision — §8's imaging "outside a
 * conventional encounter" is deferred; `facility_id` is the encounter's, the
 * ADR-132 originating-context convention). `patient_id` NOT NULL.
 * `clinical_indication_ref` is a nullable plain uuid with NO FK — the
 * unconstrained-provenance-pointer precedent (CP.1 `source_diagnosis_id`,
 * ADR-151 `clinical_reason_ref`, Lab.1 `clinical_indication_ref`); Radiology
 * does not constrain which Clinical record justified the order (§22, §98–§99).
 * `clinical_indication_text` / `rejection_reason` / `cancellation_reason` are
 * FREE TEXT, row-only — NEVER in an event or audit metadata (the ADR-136
 * deny-list; `reason` IS allowed on the audit row).
 *
 * Rad.1 lifecycle is the FRONT of the §20 / §104 machine only: DRAFT → ORDERED
 * → ACCEPTED, plus DRAFT|ORDERED → CANCELLED and ORDERED → REJECTED. SCHEDULED
 * / READY / IN_PROGRESS / COMPLETED / REPORTED / VERIFIED / RELEASED /
 * NO_SHOW / ABORTED arrive with the study (Rad.2) and report (Rad.3)
 * increments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_imaging_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id'); // = the encounter's facility (ADR-132 originating-context convention)

            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->string('order_number'); // tenant-scoped sequential, RAD-%08d

            $table->string('priority')->default('ROUTINE'); // §21 — open-coded normalised uppercase
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

        Rls::enable('rad_imaging_orders');
    }

    public function down(): void
    {
        Rls::disable('rad_imaging_orders');
        Schema::dropIfExists('rad_imaging_orders');
    }
};
