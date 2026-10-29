<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imaging studies — the examination performed (15-radiology-domain.md §19,
 * §27–§30, §53–§60, §104–§108, §183; ADR-166 D-B). Reuses the ADR-132
 * context+time shape verbatim (the Lab.2 `lab_specimens` pattern).
 *
 * A study realizes exactly ONE ordered service (`order_item_id`) — an order may
 * produce multiple studies (§106). `patient_id` / `encounter_id` are SNAPSHOTS
 * of the order's — a study must not be attached to another patient's record
 * because of a manual mistake (§54, Invariant 2). `accession_number` is
 * `RADAC-%08d`, the radiology PROCESSING identity, distinct from the `RAD-%08d`
 * order number (§29).
 *
 * `appointment_ref` / `equipment_ref` are nullable OPAQUE strings with NO FK —
 * Scheduling owns the appointment (`blueprint §13.2`), asset management owns the
 * equipment record (§26); the Theatre T.6 `lab_accession_ref` precedent.
 * `verified_by_user_id` / `verified_at` record the §53/§54 patient-verification
 * confirmation set by `StartImagingStudy`. `outcome_status_code` is open-coded
 * normalised-uppercase (the §55/§56 reason seeds); `outcome_detail` is free
 * text, ROW-ONLY (ADR-136). `repeat_of_study_id` is a nullable self-ref with NO
 * FK (§59/§60/§108 — the `recollection_of_specimen_id` precedent).
 *
 * §104 machine: SCHEDULED → READY → IN_PROGRESS → COMPLETED; alt CANCELLED /
 * NO_SHOW / ABORTED. REPORTED / VERIFIED / RELEASED are the Rad.3
 * `RadiologyReport` aggregate's, NOT this table's (§105).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_imaging_studies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('order_id')->constrained('rad_imaging_orders');
            $table->foreignUuid('order_item_id')->constrained('rad_imaging_order_items');
            $table->uuid('patient_id');   // snapshot of the order's patient (wrong-patient safety, §54)
            $table->uuid('encounter_id'); // snapshot of the order's encounter

            $table->string('accession_number'); // RADAC-%08d — the processing identity (§28–§29)
            $table->string('modality_code');    // snapshot of the order item
            $table->string('service_code');     // snapshot of the order item
            $table->string('body_site')->nullable();   // snapshot of the order item
            $table->string('laterality')->nullable();   // snapshot of the order item

            $table->string('status')->default('SCHEDULED');
            $table->timestampTz('scheduled_for')->nullable(); // the intended slot (informational)
            $table->string('appointment_ref')->nullable();    // opaque, no FK — Scheduling owns booking
            $table->string('equipment_ref')->nullable();      // opaque, no FK — §26

            $table->uuid('verified_by_user_id')->nullable();  // §53/§54 patient-verification confirmation
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('performed_by_user_id')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->string('outcome_status_code')->nullable(); // open-coded (§55/§56)
            $table->text('outcome_detail')->nullable();        // free text, row-only (ADR-136)

            $table->uuid('repeat_of_study_id')->nullable();    // self-ref, no FK (§59/§60)

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'accession_number']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'order_item_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('rad_imaging_studies');
    }

    public function down(): void
    {
        Rls::disable('rad_imaging_studies');
        Schema::dropIfExists('rad_imaging_studies');
    }
};
