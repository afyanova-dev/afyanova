<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment CP.1) — the procedure INSTANCE: this specific procedure
 * performed on this patient, during this encounter
 * (13-clinical-procedures-domain.md §9–§11, §32–§53, §80–§91; ADR-137, ADR-146).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order, then the procedure's own fields. `facility_id` comes
 * from the encounter context, never client input (Invariant 12, ADR-135).
 * `authored_context` is `CLINICIAN` | `NURSING` (ADR-146 D-E — the ADR-144
 * mechanism; 13 §18, a procedure is frequently nurse-performed).
 *
 * `procedure_number` is `PROC-%08d` from `clin_procedure_number_counters`
 * (ADR-137). `definition_id` is the ONE real FK on this table — intra-`clin_`,
 * stable reference data (IC §10; the `clin_note_amendments` FK precedent).
 * `definition_version` SNAPSHOTS `clin_procedure_definitions.version` at
 * `RequestProcedure` (13 §112) — never a copy of the definition body.
 *
 * `status` is `ClinicalProcedureStatus` (ADR-146 D-F — the ONE `generated.ts`
 * growth since C.1). The transition timestamps (`requested_at` … `completed_at`)
 * are each set ONCE by their command, server time, never rewritten (ADR-132).
 * `duration` is DERIVED (`started_at` → `completed_at`), never stored (13 §84).
 *
 * `site` is OPEN free text; `laterality` is the CLOSED `ProcedureLaterality` set
 * (ADR-146 D-H) — both required only when the definition flags them, validated
 * at `StartProcedure`. `outcome` is OPEN coded (ADR-146 D-I); `outcome_detail` /
 * `complications` / `status_reason` / `indication` / `location_label` are free
 * text — NEVER carried in an event payload or an audit row (ADR-136 deny-list).
 *
 * `source_diagnosis_id` / `source_clinical_plan_id` / `clinical_note_id` are
 * PLAIN uuid, NO FK, cross-aggregate provenance pointers whose targets have
 * their own independent lifecycles (the ADR-140 D-I precedent — ADR-146 D-K /
 * S6). `financial_clearance_ref` / `financial_policy_version` are pass-through,
 * not enforced (ADR-137; the ADR-132 → CP.1 `policy_version` deferral
 * discharged — 13 §119).
 *
 * RLS is tenant-scoped + FORCE, NO facility predicate (the
 * `clin_triage_assessments` precedent) — originating-facility isolation is an
 * application-layer refinement (ADR-146 D-O / D-G).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_procedures', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();           // from the encounter context (ADR-135)
            $table->uuid('patient_id');                     // plain uuid, NO FK
            $table->uuid('encounter_id');                   // plain uuid, NO FK
            $table->uuid('author_user_id');                 // = requested_by_user_id
            $table->string('authored_context')->nullable(); // CLINICIAN | NURSING (ADR-146 D-E)

            $table->string('procedure_number')->unique();   // PROC-%08d, tenant-wide unique (ADR-137)
            $table->string('status');                       // ClinicalProcedureStatus (ADR-146 D-F)

            $table->foreignUuid('definition_id')->constrained('clin_procedure_definitions'); // the ONE real FK
            $table->unsignedInteger('definition_version');  // snapshot at RequestProcedure (13 §112)
            $table->string('procedure_category')->nullable(); // snapshot of the definition's category at
            // RequestProcedure — denormalised so every CP.1 event (ADR-146 D-R) can
            // carry the coded category without a reader call per transition, and so
            // it survives a later catalog re-categorisation (the definition_version
            // snapshot precedent, 13 §112).

            $table->uuid('requested_by_user_id');
            $table->uuid('authorized_by_user_id')->nullable();
            $table->uuid('performed_by_user_id')->nullable(); // set once at IN_PROGRESS (13 §19)

            $table->string('location_label')->nullable();   // OPEN free text, documentation only (13 §24)
            $table->string('site')->nullable();              // OPEN (13 §47)
            $table->string('laterality')->nullable();        // CLOSED ProcedureLaterality set (13 §48)
            $table->string('outcome')->nullable();            // OPEN coded (13 §38, §85)
            $table->text('outcome_detail')->nullable();
            $table->text('complications')->nullable();       // 13 §86
            $table->string('status_reason')->nullable();      // abort/cancel/decline rationale (13 §34/§35/§37)

            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->uuid('source_diagnosis_id')->nullable();      // read-only provenance, NO FK (13 §41/§49)
            $table->uuid('source_clinical_plan_id')->nullable();  // read-only provenance, NO FK (13 §42)
            $table->text('indication')->nullable();               // free text (13 §49)
            $table->uuid('clinical_note_id')->nullable();         // the procedure note link, NO FK (ADR-146 D-L)

            $table->string('financial_clearance_ref')->nullable();  // pass-through (13 §57)
            $table->string('financial_policy_version')->nullable(); // pass-through (13 §119)

            $table->timestampTz('occurred_at');   // CLINICAL time care began (ADR-132; ADR-146 D-E)
            $table->timestampTz('recorded_at');   // server write time (ADR-132)

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);              // "a patient's procedures"
            $table->index(['tenant_id', 'encounter_id']);            // "an encounter's procedures"
            $table->index(['tenant_id', 'encounter_id', 'occurred_at']); // "an encounter's procedures, in order"
            $table->index('definition_id');
        });

        Rls::enable('clin_procedures');
    }

    public function down(): void
    {
        Rls::disable('clin_procedures');
        Schema::dropIfExists('clin_procedures');
    }
};
