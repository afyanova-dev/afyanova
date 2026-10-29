<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.5) — the append-only pre-change history for an allergy
 * assertion (12-clinical-domain.md §15, §74–§75, §162; ADR-141 D-J). Every
 * `UpdateAllergy` (content) and every `VerifyAllergy` (verification) appends
 * exactly one row here capturing the COMPLETE clinical state of the allergy the
 * instant BEFORE the change; the previous state is never lost and
 * `clin_allergies` is never silently overwritten.
 *
 * `ResolveAllergy` does NOT append here (ADR-141 D-J — the ADR-140 D-P
 * `ResolveProblem` precedent): resolution is preserved by the `AllergyResolved`
 * event + the `clinical.allergies.resolved` audit + the set-once
 * `clinical_status` / `resolved_at` columns on `clin_allergies`.
 *
 * `change_kind` distinguishes the two history-writing paths:
 *   - `CONTENT`      — `change_type` is `REVISION` | `CORRECTION`;
 *   - `VERIFICATION` — `change_type` is the coded target `CONFIRMED` | `REFUTED`.
 * `reason` is REQUIRED clinical data for a `CORRECTION` and for a `REFUTED`
 * verification (null otherwise) and lives on this row ONLY — never copied to
 * the audit entry or the domain event (ADR-141 D-Q / D-R).
 *
 * The pre-change snapshot is the complete set of the allergy's clinical fields:
 * `category` / `substance_*` / `reaction_description` / `severity` /
 * `clinical_status` / `verification_status` / `onset_date`.
 *
 * `allergy_id` carries a real foreign key to `clin_allergies` — an INTRA-domain
 * reference (both `clin_`), permitted by IC §10 (only cross-prefix FKs are
 * forbidden). `patient_id` / `encounter_id` / `author_user_id` are plain uuids,
 * denormalised from the parent, with NO FK.
 *
 * Append-only at the database: `Rls::enableAppendOnly` adds RESTRICTIVE
 * no-UPDATE / no-DELETE policies (the `clin_problem_history` /
 * `clin_note_amendments` precedent). The `version` column exists only to
 * satisfy the aggregate table shape (IC §9.1 / §10) and stays 0 for the row's
 * whole life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_allergy_history', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                   // copied from the parent allergy (ADR-141 D-B)
            $table->foreignUuid('allergy_id')->constrained('clin_allergies'); // intra-domain FK (IC §10)
            $table->uuid('patient_id');                    // denormalised from the allergy, NO FK
            $table->uuid('encounter_id');                  // denormalised from the allergy, NO FK

            $table->string('change_kind');                 // CONTENT | VERIFICATION (ADR-141 D-J)
            $table->string('change_type')->nullable();     // REVISION | CORRECTION (CONTENT) | CONFIRMED | REFUTED (VERIFICATION)

            // PRE-CHANGE snapshot of the complete clinical state (ADR-141 D-J).
            $table->string('category');
            $table->text('substance_name');
            $table->string('substance_code')->nullable();
            $table->string('substance_code_system')->nullable();
            $table->text('reaction_description')->nullable();
            $table->string('severity')->nullable();
            $table->string('clinical_status');
            $table->string('verification_status');
            $table->date('onset_date')->nullable();

            $table->text('reason')->nullable();            // CORRECTION / REFUTED rationale — clinical data (NULL otherwise)
            $table->uuid('author_user_id');                // who made the change

            $table->timestampTz('occurred_at');            // CLINICAL time of the change (server time — ADR-141 D-H)
            $table->timestampTz('recorded_at');            // server write time of the change

            $table->unsignedBigInteger('version')->default(0); // stays 0 — append-only
            $table->timestampsTz();

            $table->index(['tenant_id', 'allergy_id']);    // "an allergy's history, in order"
        });

        Rls::enable('clin_allergy_history');
        Rls::enableAppendOnly('clin_allergy_history');
    }

    public function down(): void
    {
        Rls::disable('clin_allergy_history');
        Schema::dropIfExists('clin_allergy_history');
    }
};
