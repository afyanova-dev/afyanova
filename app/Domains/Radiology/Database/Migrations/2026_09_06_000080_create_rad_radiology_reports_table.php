<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology reports (15-radiology-domain.md §40–§45, §96, §103, §158, §161–§166,
 * §184, §188; ADR-167 D-B). One PROSE report per study. Reuses the ADR-132
 * context+time shape verbatim (the Lab.3a `lab_results` + T.2 `OperativeNote`
 * pattern).
 *
 * `study_id` is `unique(tenant_id, study_id)` — a study has exactly one report
 * (PRELIMINARY / FINAL §49/§50 declared-not-wired). `order_id` / `patient_id` /
 * `encounter_id` / `accession_number` / `modality_code` / `service_code` are
 * SNAPSHOTS of the study's (wrong-patient safety §54, Inv 2).
 *
 * The report body is FREE TEXT, ROW-ONLY (§40 sections — never event / audit
 * metadata, the ADR-136 deny-list): `clinical_indication_text` /
 * `technique_text` / `findings_text` / `impression_text` /
 * `recommendations_text`. `findings_text` + `impression_text` are required at
 * VERIFY (§161 — a report cannot be verified empty). Coded / structured
 * findings (§38 / §97) are a later refinement.
 *
 * `status` (§103): DRAFT → REVIEWED → VERIFIED → RELEASED; alt AMENDED /
 * CANCELLED (Rad.3b). `release_hold_reason` is the generic release-block hook
 * (reserved — the Lab.3a precedent). `amendment_count` + `cancellation_reason`
 * are reserved for Rad.3b (the T.2 dirty-the-row lesson).
 * `critical_finding_flagged` is reserved for Rad.3c.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_radiology_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('study_id')->constrained('rad_imaging_studies');
            $table->foreignUuid('order_id')->constrained('rad_imaging_orders');
            $table->uuid('order_item_id');
            $table->uuid('patient_id');   // snapshot of the study's patient (wrong-patient safety, §54)
            $table->uuid('encounter_id'); // snapshot
            $table->string('accession_number'); // snapshot of the study
            $table->string('modality_code');    // snapshot
            $table->string('service_code');     // snapshot

            $table->text('clinical_indication_text')->nullable(); // §40 — free text, row-only (ADR-136)
            $table->text('technique_text')->nullable();
            $table->text('findings_text')->nullable();
            $table->text('impression_text')->nullable();
            $table->text('recommendations_text')->nullable();

            $table->string('status')->default('DRAFT');
            $table->string('release_hold_reason')->nullable();  // generic block hook — reserved
            $table->unsignedInteger('amendment_count')->default(0); // reserved for Rad.3b
            $table->text('cancellation_reason')->nullable();    // reserved for Rad.3b — free text, row-only
            $table->boolean('critical_finding_flagged')->default(false); // reserved for Rad.3c

            $table->uuid('authored_by_user_id');
            $table->timestampTz('authored_at');
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('verified_by_user_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('released_by_user_id')->nullable();
            $table->timestampTz('released_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'study_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('rad_radiology_reports');
    }

    public function down(): void
    {
        Rls::disable('rad_radiology_reports');
        Schema::dropIfExists('rad_radiology_reports');
    }
};
