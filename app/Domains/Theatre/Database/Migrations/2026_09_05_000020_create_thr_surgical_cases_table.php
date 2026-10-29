<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surgical cases — the perioperative operational record (17-theatre-domain.md
 * §9, §219; ADR-150 D-D). Reuses the ADR-132 context+time shape.
 *
 * T.1 folds a single planned/actual procedure, the safety checklist, and
 * recovery-destination fields directly onto this aggregate rather than the
 * domain doc's own separate SurgicalProcedure/RecoveryHandoff aggregates
 * (ADR-150 D-C — deferred to T.2+).
 *
 * `scheduled_for` is not itemized in ADR-150 D-C's own table-shape list, but
 * is structurally required by D-D's SCHEDULED state and D-I's
 * ScheduleSurgicalCase/RescheduleSurgicalCase commands — found and added
 * during implementation, the same class of gap already caught elsewhere in
 * this ADR (the missing CreateOperatingRoom command).
 *
 * Concurrency: TWO partial unique indexes from day one (ADR-150 D-E) — the
 * Bed & Ward B.1->B.2 concurrency-gap lesson applied proactively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_surgical_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->uuid('operating_room_id')->nullable();
            $table->string('case_number');

            $table->string('priority')->default('ELECTIVE');
            $table->timestampTz('scheduled_for')->nullable();
            $table->string('planned_procedure')->nullable();
            $table->string('actual_procedure')->nullable();
            $table->text('indication')->nullable();
            $table->text('findings')->nullable();
            $table->string('outcome')->nullable();
            $table->text('complications')->nullable();

            $table->jsonb('safety_checklist')->default('[]');
            $table->string('recovery_destination_type')->nullable();

            $table->string('status')->default('REQUESTED');

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'case_number']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'encounter_id']);
            $table->index(['tenant_id', 'operating_room_id', 'status']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX thr_surgical_cases_one_active_per_room
             ON thr_surgical_cases (operating_room_id)
             WHERE status IN ('READY', 'IN_PROGRESS')"
        );

        DB::statement(
            "CREATE UNIQUE INDEX thr_surgical_cases_one_active_per_encounter
             ON thr_surgical_cases (encounter_id)
             WHERE status IN ('READY', 'IN_PROGRESS')"
        );

        Rls::enable('thr_surgical_cases');
    }

    public function down(): void
    {
        Rls::disable('thr_surgical_cases');
        Schema::dropIfExists('thr_surgical_cases');
    }
};
