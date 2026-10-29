<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.4) — the append-only pre-change history for a
 * longitudinal problem (12-clinical-domain.md §15, §74, §162; ADR-140
 * D-D / D-P). Every `UpdateProblem` appends exactly one row here capturing the
 * COMPLETE clinical state of the problem the instant BEFORE the change; the
 * previous state is never lost and `clin_problems` is never silently
 * overwritten.
 *
 * `ResolveProblem` does NOT append here — `change_type` is `REVISION` |
 * `CORRECTION` only; a resolution is preserved by the `ProblemResolved` event +
 * the `clinical.problems.resolved` audit + the set-once `status` / `resolved_at`
 * columns on `clin_problems` (ADR-140 D-P).
 *
 * The ADR-132 context / attribution / clinical-time shape, plus the pre-change
 * snapshot (`code` / `code_system` / `description` / `status` / `onset_date` /
 * `resolved_at`), plus `change_type` and the `CORRECTION` `reason`. `reason` is
 * REQUIRED clinical data for a `CORRECTION` (null for a `REVISION`) and lives on
 * this row only — it is never copied to the audit entry or the domain event
 * (ADR-140 D-N / D-M).
 *
 * `problem_id` carries a real foreign key to `clin_problems` — an INTRA-domain
 * reference (both `clin_`), permitted by IC §10 (only cross-prefix FKs are
 * forbidden). `patient_id` / `encounter_id` / `author_user_id` are plain uuids,
 * denormalised from the parent for query convenience, with NO FK.
 *
 * Append-only at the database: `Rls::enableAppendOnly` adds RESTRICTIVE
 * no-UPDATE / no-DELETE policies (the `clin_note_amendments` /
 * `shared_dead_letters` precedent). The `version` column exists only to satisfy
 * the aggregate table shape (IC §9.1 / §10) and stays 0 for the row's whole
 * life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_problem_history', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                   // copied from the parent problem (ADR-140 D-B)
            $table->foreignUuid('problem_id')->constrained('clin_problems'); // intra-domain FK (IC §10)
            $table->uuid('patient_id');                    // denormalised from the problem, NO FK
            $table->uuid('encounter_id');                  // denormalised from the problem, NO FK

            $table->string('change_type');                 // ProblemChangeType: REVISION | CORRECTION (ADR-140 D-J)

            // PRE-CHANGE snapshot of the complete clinical state (ADR-140 D-D / D-P).
            $table->string('code')->nullable();
            $table->string('code_system')->nullable();
            $table->text('description');
            $table->string('status');                      // ProblemStatus at snapshot time (ACTIVE or RESOLVED)
            $table->date('onset_date')->nullable();
            $table->timestampTz('resolved_at')->nullable();

            $table->text('reason')->nullable();            // CORRECTION rationale — clinical data (NULL for a REVISION)
            $table->uuid('author_user_id');                // who made the change

            $table->timestampTz('occurred_at');            // CLINICAL time of the change (ADR-140 D-P)
            $table->timestampTz('recorded_at');            // server write time of the change

            $table->unsignedBigInteger('version')->default(0); // stays 0 — append-only
            $table->timestampsTz();

            $table->index(['tenant_id', 'problem_id']);    // "a problem's history, in order"
        });

        Rls::enable('clin_problem_history');
        Rls::enableAppendOnly('clin_problem_history');
    }

    public function down(): void
    {
        Rls::disable('clin_problem_history');
        Schema::dropIfExists('clin_problem_history');
    }
};
