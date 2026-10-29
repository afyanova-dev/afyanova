<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.7 — ADR-143) — the append-only pre-change history for a
 * longitudinal ClinicalPlan (12-clinical-domain.md §34–§39, §76; ADR-143 D-E / D-T).
 * Every `UpdateClinicalPlan` appends exactly one row here capturing the COMPLETE
 * clinical state of the plan the instant BEFORE the change; previous state is
 * never lost and `clin_plans` is never silently overwritten.
 *
 * `CloseClinicalPlan` does NOT append here (ADR-143 D-E — the ADR-140 D-P
 * ResolveProblem precedent): completion / discontinuation is preserved by
 * `closed_reason` + `status` on `clin_plans` and the `clinical.plans.closed` audit row.
 *
 * `reason` is REQUIRED clinical data for a `CORRECTION` (null for a `REVISION`)
 * and lives on this row only — never copied to audit entry or domain event (ADR-143 D-E / D-S).
 *
 * Append-only at database: `Rls::enableAppendOnly` adds RESTRICTIVE
 * no-UPDATE / no-DELETE policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_plan_history', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                   // copied from the parent plan (ADR-143 D-C)
            $table->foreignUuid('plan_id')->constrained('clin_plans'); // intra-domain FK (IC §10)
            $table->uuid('patient_id');                    // denormalised from plan, NO FK
            $table->uuid('encounter_id');                  // denormalised from plan, NO FK

            $table->string('change_type');                 // ClinicalPlanChangeType: REVISION | CORRECTION (ADR-143 D-E)

            // PRE-CHANGE snapshot of complete clinical state (ADR-143 D-E).
            $table->string('plan_type');
            $table->text('clinical_intent');
            $table->text('goals')->nullable();
            $table->text('planned_actions')->nullable();
            $table->text('instructions')->nullable();
            $table->text('follow_up')->nullable();
            $table->string('status');
            $table->text('closed_reason')->nullable();
            $table->uuid('indication_diagnosis_id')->nullable();
            $table->uuid('indication_problem_id')->nullable();

            $table->text('reason')->nullable();            // CORRECTION rationale — clinical data (NULL for REVISION)
            $table->uuid('author_user_id');                // who made the change
            $table->string('authored_context')->default('CLINICIAN');

            $table->timestampTz('occurred_at');            // CLINICAL time of change
            $table->timestampTz('recorded_at');            // server write time of change

            $table->unsignedBigInteger('version')->default(0); // stays 0 — append-only
            $table->timestampsTz();

            $table->index(['tenant_id', 'plan_id']);       // "a plan's history, in order"
        });

        Rls::enable('clin_plan_history');
        Rls::enableAppendOnly('clin_plan_history');
    }

    public function down(): void
    {
        Rls::disable('clin_plan_history');
        Schema::dropIfExists('clin_plan_history');
    }
};
