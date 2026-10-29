<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.3) — the encounter diagnosis: one discrete coded
 * clinical conclusion recorded during care (12-clinical-domain.md §23–§27,
 * §73, §102; ADR-131, ADR-132, ADR-139).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order, then the diagnosis's own `code` / `code_system` /
 * `description` / `clinical_status`, then the ADR-139 D4 supersession columns,
 * then `occurred_at` / `recorded_at`, then `version`.
 *
 * `code` / `code_system` are OPEN strings (ADR-139 D2) — no `DiagnosisCodeSystem`
 * enum, no terminology catalogue, no ICD / SNOMED validation. `code_system` is
 * set once at `RecordDiagnosis` and never modified (§102). `description` is
 * required non-blank clinical text (D7). `clinical_status` is an open coded
 * string, default `WORKING` (D3). There is **no** rank / primary / secondary /
 * onset / resolved_at / laterality / body-site / source / template / terminology
 * version / problem-link column (ADR-139 D5/D6/D8) and **no** CHECK constraint.
 *
 * NO foreign key crosses a table-prefix boundary (IC §10, ADR-093, ADR-135):
 * `patient_id`, `encounter_id`, `author_user_id`, `superseded_by_diagnosis_id`
 * are plain indexed uuids with NO database FK. `supersedes_diagnosis_id`
 * carries an INTRA-domain self-referential FK (ADR-139 — permitted, IC §10).
 *
 * `facility_id` comes from the encounter context, never client input
 * (Invariant 12, ADR-135). RLS is tenant-scoped + FORCE (the `clin_notes` /
 * `clin_vital_sign_observations` precedent); the table is NOT append-only — the
 * one-time supersession stamp (ADR-139 D4) is a guarded versioned UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_diagnoses', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // from the encounter context (ADR-135)
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // plain uuid, NO FK
            $table->uuid('author_user_id');                // the diagnosing clinician
            $table->string('authored_context')->nullable(); // always 'CLINICIAN' for C.3 (ADR-139 D13)

            $table->string('code')->nullable();            // OPEN string, optional (ADR-139 D2)
            $table->string('code_system')->nullable();     // OPEN string, required iff code present; set once (12 §102)
            $table->text('description');                   // REQUIRED non-blank clinical text (ADR-139 D7)
            $table->string('clinical_status')->default('WORKING'); // OPEN behaviour-neutral coded string (ADR-139 D3)

            // Supersession / correction chain (ADR-139 D4). The self-referential
            // FK is added after CREATE TABLE (below) so the primary key exists.
            $table->uuid('supersedes_diagnosis_id')->nullable();
            $table->timestampTz('superseded_at')->nullable();  // set once when a later correction supersedes this row
            $table->uuid('superseded_by_diagnosis_id')->nullable(); // plain uuid, NO FK (forward reference)
            $table->text('reason')->nullable();                // correction rationale — clinical data (NULL on an original)

            $table->timestampTz('occurred_at');               // CLINICAL time the conclusion was reached (ADR-132)
            $table->timestampTz('recorded_at');               // server write time (ADR-132)

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);       // "a patient's diagnoses"
            $table->index(['tenant_id', 'encounter_id']);     // "an encounter's diagnoses"
            $table->index(['tenant_id', 'encounter_id', 'occurred_at']); // "an encounter's diagnoses, in order"
            $table->index('supersedes_diagnosis_id');
        });

        // Intra-domain self-referential FK (ADR-139 — permitted, IC §10) — added
        // after the table (and its primary key) exists.
        Schema::table('clin_diagnoses', function (Blueprint $table): void {
            $table->foreign('supersedes_diagnosis_id')
                ->references('id')->on('clin_diagnoses');
        });

        Rls::enable('clin_diagnoses');
    }

    public function down(): void
    {
        Rls::disable('clin_diagnoses');
        Schema::dropIfExists('clin_diagnoses');
    }
};
