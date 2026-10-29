<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.1) — the clinical note: one unit of clinical
 * documentation authored against exactly one patient and one encounter
 * (12-clinical-domain.md §10–§15; ADR-131, ADR-132, ADR-134, ADR-135).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order: `id`, `tenant_id`, `facility_id`, `patient_id`,
 * `encounter_id`, `author_user_id`, `authored_context`, then the note's own
 * fields, then `occurred_at` / `recorded_at`, then `version`.
 *
 * NO foreign key crosses a table-prefix boundary (IC §10, ADR-093, ADR-135):
 * `patient_id` references `pat_patients`, `encounter_id` references
 * `enc_encounters`, `author_user_id` / `signed_by_user_id` reference `users` —
 * all plain indexed uuids with NO database FK. Integrity is a contract concern
 * (`CreateClinicalNote` validates the patient via `PatientApi` and the
 * encounter via `EncounterApi` first).
 *
 * `facility_id` comes from the encounter context, never client input
 * (Invariant 12, ADR-135). RLS is tenant-scoped + FORCE (the `enc_encounters`
 * precedent); facility isolation is an application-layer concern
 * (`ClinicalNotePolicy` + the handler refinements — ADR-133).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // from the encounter context (ADR-135)
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // plain uuid, NO FK
            $table->uuid('author_user_id');                // the authenticated actor — the clinical author
            $table->string('authored_context')->nullable(); // open coded string: CLINICIAN for C.1 (ADR-132)

            $table->string('note_type');                   // ClinicalNoteType VO — open coded string (ADR-134)
            $table->string('title')->nullable();
            $table->text('body');                          // Clinical-owned content; Documents renders it in PHASE 12
            $table->string('status')->default('DRAFT');    // ClinicalNoteStatus enum: DRAFT | SIGNED | CANCELLED

            $table->timestampTz('signed_at')->nullable();  // set once by SignClinicalNote (ADR-132)
            $table->uuid('signed_by_user_id')->nullable(); // set once by SignClinicalNote
            $table->unsignedInteger('amendment_count')->default(0); // derived AMENDED signal + dirties the row on amend

            $table->timestampTz('occurred_at');            // CLINICAL event time (ADR-132) — may predate recorded_at
            $table->timestampTz('recorded_at');            // server write time (ADR-132) — never caller-supplied

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);    // "a patient's notes"
            $table->index(['tenant_id', 'encounter_id']);  // "an encounter's notes"
        });

        Rls::enable('clin_notes');
    }

    public function down(): void
    {
        Rls::disable('clin_notes');
        Schema::dropIfExists('clin_notes');
    }
};
