<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.9) — the triage assessment: one rapid, point-in-time,
 * structured clinical conclusion recorded when a patient presents for care
 * (ADR-105, ADR-145; 11-encounter-domain.md §166 / §53; 12-clinical-domain.md
 * §16 / §28 / §49 / §871 / §1895).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order, then the assessment's own `chief_complaint` /
 * `triage_priority` / `acuity` / `consciousness` / `source_vitals_observation_id`,
 * then the ADR-145 D-F supersession columns, then `occurred_at` / `recorded_at`,
 * then `version`.
 *
 * `chief_complaint` is REQUIRED free text — the patient's stated reason for the
 * visit — and is NEVER carried in an event payload or an audit row (ADR-136
 * deny-list; ADR-145 D-E). `triage_priority` is a CLOSED coded string
 * `{EMERGENCY | URGENT | HIGH | NORMAL | LOW}` (`TriagePriority`; 23 §21) — the
 * one behaviour-carrying field; behaviour-neutral within Clinical (ADR-139 D3 /
 * ADR-145 O-2). `acuity` and `consciousness` are OPEN coded strings, shape-
 * validated only — NO scale enum, NO scoring engine, NO terminology engine
 * (ADR-145 O-1). `authored_context` is `CLINICIAN` | `NURSING` (ADR-145 D-M —
 * the ADR-144 mechanism). There is **no** status column, **no** disposition /
 * destination column (ADR-143 Q19 — deferred), **no** CHECK constraint.
 *
 * NO foreign key crosses a table-prefix boundary (IC §10, ADR-093, ADR-135):
 * `patient_id`, `encounter_id`, `author_user_id`,
 * `source_vitals_observation_id`, `superseded_by_triage_assessment_id` are
 * plain indexed uuids with NO database FK. `source_vitals_observation_id` is a
 * READ-ONLY provenance pointer (validated at record time via the C.2 reader —
 * the ADR-140 D-I precedent; R-2) and is kept FK-free because the referenced
 * C.2 observation has its own independent lifecycle (it can itself be
 * superseded). `supersedes_triage_assessment_id` carries an INTRA-domain
 * self-referential FK (ADR-145 R-3 — the `clin_prescribing_decisions`
 * precedent; permitted, IC §10).
 *
 * `facility_id` comes from the encounter context, never client input
 * (Invariant 12, ADR-135). RLS is tenant-scoped + FORCE (the `clin_diagnoses` /
 * `clin_prescribing_decisions` precedent); the table is NOT append-only and has
 * NO facility predicate — the one-time supersession stamp (ADR-145 D-F) is a
 * guarded versioned UPDATE, and originating-facility isolation is an
 * application-layer refinement (ADR-145 D-L / D-P).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_triage_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // from the encounter context (ADR-135)
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // plain uuid, NO FK
            $table->uuid('author_user_id');                // the triage clinician / nurse
            $table->string('authored_context')->nullable(); // CLINICIAN | NURSING (ADR-145 D-M)

            $table->text('chief_complaint');                        // REQUIRED free text (ADR-145 D-E) — never in event / audit
            $table->string('triage_priority');                      // CLOSED coded set {EMERGENCY|URGENT|HIGH|NORMAL|LOW} (ADR-145 D-E)
            $table->string('acuity')->nullable();                   // OPEN coded string, documentation only (ADR-145 O-1)
            $table->string('consciousness')->nullable();            // OPEN coded string, documentation only (ADR-145 O-1)
            $table->uuid('source_vitals_observation_id')->nullable(); // OPTIONAL read-only C.2 provenance pointer, NO FK (ADR-145 R-2)

            // Supersession / re-triage chain (ADR-145 D-F). The self-referential
            // FK is added after CREATE TABLE (below) so the primary key exists.
            $table->uuid('supersedes_triage_assessment_id')->nullable();
            $table->timestampTz('superseded_at')->nullable();       // set once when a later assessment supersedes this row
            $table->uuid('superseded_by_triage_assessment_id')->nullable(); // plain uuid, NO FK (forward reference)
            $table->string('reason')->nullable();                   // correction / re-triage rationale — clinical data (NULL on an original)

            $table->timestampTz('occurred_at');                     // CLINICAL time the triage was performed (ADR-132)
            $table->timestampTz('recorded_at');                     // server write time (ADR-132)

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);             // "a patient's triage assessments"
            $table->index(['tenant_id', 'encounter_id']);           // "an encounter's triage assessments"
            $table->index(['tenant_id', 'encounter_id', 'occurred_at']); // "an encounter's triage assessments, in order"
            $table->index('supersedes_triage_assessment_id');
        });

        // Intra-domain self-referential FK (ADR-145 R-3 — the
        // clin_prescribing_decisions precedent; permitted, IC §10) — added after
        // the table (and its primary key) exists.
        Schema::table('clin_triage_assessments', function (Blueprint $table): void {
            $table->foreign('supersedes_triage_assessment_id')
                ->references('id')->on('clin_triage_assessments');
        });

        Rls::enable('clin_triage_assessments');
    }

    public function down(): void
    {
        Rls::disable('clin_triage_assessments');
        Schema::dropIfExists('clin_triage_assessments');
    }
};
