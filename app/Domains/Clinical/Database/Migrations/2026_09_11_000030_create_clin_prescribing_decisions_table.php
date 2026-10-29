<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.7 — ADR-143) — PrescribingDecision: encounter-originated
 * longitudinal therapeutic prescribing decision for a patient (12-clinical-domain.md
 * §34–§39, §76; 16-pharmacy-domain.md §6, §19, §21; ADR-131, ADR-132, ADR-135,
 * ADR-136, ADR-143).
 *
 * Clinical owns the therapeutic prescribing DECISION (what, why, full dosing
 * intent); Pharmacy owns the authoritative Prescription RECORD, lifecycle and
 * dispensing (PHASE 9).
 *
 * LIFECYCLE (ADR-143 D-J): IMMUTABLE RECORDED + superseding-record correction
 * (the C.3 Diagnosis model) + a clinical-stop status (DISCONTINUED).
 *
 * RLS is tenant-scoped + FORCE; table is NOT append-only (the supersession stamp
 * and discontinuation status are versioned UPDATEs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_prescribing_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // ORIGINATING facility (ADR-143 D-H), from encounter context
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // ORIGINATING encounter (ADR-143 D-H), plain uuid, NO FK
            $table->uuid('author_user_id');                // recording clinician
            $table->string('authored_context')->default('CLINICIAN'); // always 'CLINICIAN' for C.7 (ADR-143 D-V)

            // Clinical content of the prescribing decision (ADR-143 D-I).
            $table->text('medication_name');               // REQUIRED non-blank
            $table->string('medication_code')->nullable(); // OPEN string, optional
            $table->string('medication_code_system')->nullable(); // OPEN string, required iff code present
            $table->string('dose')->nullable();            // free text ("500 mg")
            $table->string('route')->nullable();           // OPEN coded string
            $table->string('frequency')->nullable();       // free text ("TDS")
            $table->string('duration')->nullable();        // free text ("7 days")
            $table->string('quantity')->nullable();        // PRESCRIBED quantity
            $table->text('dosing_instruction')->nullable(); // free text ("take with food")
            $table->text('therapeutic_intent')->nullable(); // "why"
            $table->text('indication')->nullable();        // free text
            $table->string('urgency')->nullable();         // OPEN coded string

            $table->string('status')->default('ACTIVE');   // PrescribingDecisionStatus: ACTIVE | SUPERSEDED | DISCONTINUED (ADR-143 D-J)
            $table->text('discontinued_reason')->nullable(); // clinical rationale for discontinuation (ADR-143 D-J)
            $table->text('correction_reason')->nullable(); // correction rationale on a superseding row (ADR-143 D-J)

            $table->uuid('supersedes_prescribing_decision_id')->nullable();
            $table->timestampTz('superseded_at')->nullable(); // set once when superseded
            $table->uuid('superseded_by_prescribing_decision_id')->nullable(); // pointer to superseding row

            $table->uuid('clinical_plan_id')->nullable();  // OPTIONAL link to ClinicalPlan, plain uuid, NO FK (ADR-143 D-K)
            $table->uuid('indication_diagnosis_id')->nullable(); // OPTIONAL provenance, plain uuid, NO FK (ADR-143 D-M)
            $table->uuid('indication_problem_id')->nullable();   // OPTIONAL provenance, plain uuid, NO FK (ADR-143 D-M)

            $table->timestampTz('occurred_at');            // CLINICAL time decision made (ADR-132) — set once
            $table->timestampTz('recorded_at');            // server write time (ADR-132) — set once

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);            // "a patient's prescribing decisions"
            $table->index(['tenant_id', 'patient_id', 'status']);  // "a patient's decisions by status"
            $table->index(['tenant_id', 'encounter_id']);          // "decisions originated at an encounter"
            $table->index(['tenant_id', 'clinical_plan_id']);      // "decisions linked to a plan"
            $table->index('supersedes_prescribing_decision_id');
        });

        // Intra-domain self-referential FK (IC §10) — added after the table exists
        Schema::table('clin_prescribing_decisions', function (Blueprint $table): void {
            $table->foreign('supersedes_prescribing_decision_id')
                ->references('id')->on('clin_prescribing_decisions');
        });

        Rls::enable('clin_prescribing_decisions');
    }

    public function down(): void
    {
        Rls::disable('clin_prescribing_decisions');
        Schema::dropIfExists('clin_prescribing_decisions');
    }
};
