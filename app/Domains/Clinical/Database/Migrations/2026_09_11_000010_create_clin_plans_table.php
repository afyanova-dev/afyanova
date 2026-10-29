<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.7 — ADR-143) — ClinicalPlan: encounter-originated
 * longitudinal treatment / management plan for a patient (12-clinical-domain.md
 * §34–§39, §76; ADR-131, ADR-132, ADR-135, ADR-143).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order, then plan_type, clinical_intent, goals, planned_actions,
 * instructions (clinical instructions facet), follow_up (follow-up intent facet),
 * status, closed_reason, indication_* plain uuids, occurred_at / recorded_at,
 * version, and timestampsTz().
 *
 * ORIGIN (ADR-143 D-C): `encounter_id` = the ORIGINATING encounter and
 * `facility_id` = the ORIGINATING facility (from the encounter context, never
 * client input — Invariant 12). Both are immutable for life; `facility_id` is
 * the authorization / originating-facility-visibility anchor.
 *
 * RLS is tenant-scoped + FORCE; table is NOT append-only (bounded-mutation
 * versioned UPDATEs). Facility isolation is app-layer (ADR-143 D-P).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // ORIGINATING facility (ADR-143 D-C), from encounter context
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // ORIGINATING encounter (ADR-143 D-C), plain uuid, NO FK
            $table->uuid('author_user_id');                // recording clinician
            $table->string('authored_context')->default('CLINICIAN'); // always 'CLINICIAN' for C.7 (ADR-143 D-V)

            $table->string('plan_type');                   // ClinicalPlanType (ADR-143 D-D)
            $table->text('clinical_intent');               // REQUIRED clinical intent (ADR-143 D-D)
            $table->text('goals')->nullable();             // OPTIONAL free text
            $table->text('planned_actions')->nullable();   // OPTIONAL free text
            $table->text('instructions')->nullable();      // OPTIONAL clinical instructions facet (ADR-143 D-F)
            $table->text('follow_up')->nullable();         // OPTIONAL follow-up intent facet (ADR-143 D-G)

            $table->string('status')->default('ACTIVE');   // ClinicalPlanStatus: ACTIVE | COMPLETED | DISCONTINUED (ADR-143 D-E)
            $table->text('closed_reason')->nullable();     // set once on DISCONTINUED (ADR-143 D-E)

            $table->uuid('indication_diagnosis_id')->nullable(); // OPTIONAL provenance only — plain uuid, NO FK (ADR-143 D-M)
            $table->uuid('indication_problem_id')->nullable();   // OPTIONAL provenance only — plain uuid, NO FK (ADR-143 D-M)

            $table->timestampTz('occurred_at');            // CLINICAL time plan created (ADR-132) — set once
            $table->timestampTz('recorded_at');            // server write time (ADR-132) — set once

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);            // "a patient's clinical plans"
            $table->index(['tenant_id', 'patient_id', 'status']);  // "a patient's plans by status"
            $table->index(['tenant_id', 'encounter_id']);          // "plans originated at an encounter"
        });

        Rls::enable('clin_plans');
    }

    public function down(): void
    {
        Rls::disable('clin_plans');
        Schema::dropIfExists('clin_plans');
    }
};
