<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.4) — the longitudinal problem: one encounter-originated
 * problem-list entry for a patient, tracked over time and eventually resolved
 * (12-clinical-domain.md §25–§27, §74; ADR-131, ADR-132, ADR-135, ADR-140).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order, then the problem's own `code` / `code_system` /
 * `description` / `status`, then the ADR-140 temporal + provenance columns,
 * then `occurred_at` / `recorded_at`, then `version`.
 *
 * ORIGIN (ADR-140 D-A / D-B): `encounter_id` = the ORIGINATING encounter and
 * `facility_id` = the ORIGINATING facility (from the encounter context, never
 * client input — Invariant 12). Both are immutable for life; `facility_id` is
 * the authorization / originating-facility-visibility anchor.
 *
 * `code` / `code_system` are OPEN strings (ADR-140 D-G) — no `ProblemCodeSystem`
 * enum, no terminology catalogue, no ICD / SNOMED validation. Unlike C.3
 * `clin_diagnoses.code_system` they are MUTABLE (via `UpdateProblem`); the
 * pre-change value is preserved in `clin_problem_history`. `description` is
 * required non-blank clinical text (D-H). `status` is `ACTIVE` | `RESOLVED`
 * (ProblemStatus, ADR-140 D-E), default `ACTIVE`, never directly writable.
 * `onset_date` is nullable + mutable (D-F); `resolved_at` is set once by
 * `ResolveProblem` (D-F); `source_diagnosis_id` is OPTIONAL provenance only —
 * a plain uuid with NO FK, NO Diagnosis mutation, NO back-reference (D-I).
 *
 * There is **no** rank / primary / laterality / body-site / template / status-
 * history column, **no** uniqueness constraint (a patient may hold the same
 * coded problem more than once), **no** CHECK constraint and **no** self-FK
 * (ADR-140 D-P). NO foreign key crosses a table-prefix boundary (IC §10,
 * ADR-093, ADR-135): `patient_id`, `encounter_id`, `author_user_id`,
 * `source_diagnosis_id` are plain indexed / stored uuids with NO database FK.
 *
 * RLS is tenant-scoped + FORCE (the `clin_notes` / `clin_diagnoses`
 * precedent); the table is NOT append-only — the C.4 transition commands are
 * guarded versioned UPDATEs. Facility isolation is app-layer (ADR-140 D-K —
 * the handler / policy refinement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_problems', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // ORIGINATING facility (ADR-140 D-B), from the encounter context
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // ORIGINATING encounter (ADR-140 D-A), plain uuid, NO FK
            $table->uuid('author_user_id');                // the recording clinician
            $table->string('authored_context')->nullable(); // always 'CLINICIAN' for C.4 (ADR-140 D-Q)

            $table->string('code')->nullable();            // OPEN string, optional, MUTABLE (ADR-140 D-G)
            $table->string('code_system')->nullable();     // OPEN string, required iff code present, MUTABLE
            $table->text('description');                   // REQUIRED non-blank clinical text (ADR-140 D-H), MUTABLE
            $table->string('status')->default('ACTIVE');   // ProblemStatus: ACTIVE | RESOLVED (ADR-140 D-E) — never directly writable

            $table->date('onset_date')->nullable();        // when the condition began — nullable + MUTABLE (ADR-140 D-F)
            $table->timestampTz('resolved_at')->nullable(); // resolution time — set once by ResolveProblem (ADR-140 D-F)
            $table->uuid('source_diagnosis_id')->nullable(); // OPTIONAL provenance only — plain uuid, NO FK (ADR-140 D-I)

            $table->timestampTz('occurred_at');            // CLINICAL time added to the problem list (ADR-132) — set once
            $table->timestampTz('recorded_at');            // server write time (ADR-132) — set once

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);            // "a patient's problems"
            $table->index(['tenant_id', 'patient_id', 'status']);  // "a patient's ACTIVE / RESOLVED problems"
            $table->index(['tenant_id', 'encounter_id']);          // "problems originated at an encounter"
        });

        Rls::enable('clin_problems');
    }

    public function down(): void
    {
        Rls::disable('clin_problems');
        Schema::dropIfExists('clin_problems');
    }
};
