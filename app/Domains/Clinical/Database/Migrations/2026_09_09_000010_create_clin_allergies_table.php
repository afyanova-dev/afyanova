<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.5) — the encounter-originated longitudinal
 * immune-mediated-hypersensitivity assertion: one allergy safety-record entry
 * for a patient, tracked over time (12-clinical-domain.md §30–§33, §75;
 * ADR-131, ADR-132, ADR-135, ADR-141).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order, then the allergy's own `category` / substance / reaction
 * / two-axis status / temporal columns, then `occurred_at` / `recorded_at`,
 * then `version`.
 *
 * ORIGIN (ADR-141 D-B): `encounter_id` = the ORIGINATING encounter and
 * `facility_id` = the ORIGINATING facility (from the encounter context, never
 * client input — Invariant 12). Both are immutable for life; `facility_id` is
 * the WRITE-authorization anchor. There is NO null-`encounter_id` allergy in
 * PHASE 6 (ADR-135 unamended).
 *
 * `authored_context` is CLINICIAN | NURSING (ADR-141 D-I — the ADR-138 D8
 * Vitals precedent; set-membership-validated in the write path, not an
 * authorization input). `category` is a required OPEN coded string (documented
 * DRUG | FOOD | ENVIRONMENT | BIOLOGIC | OTHER — behaviour-neutral, ADR-141
 * D-F). `substance_name` is required non-blank; `substance_code` /
 * `substance_code_system` are OPEN strings, `code_system` required iff `code`
 * present, both MUTABLE with history (ADR-141 D-E — no terminology engine).
 * `reaction_description` + `severity` are inline (ADR-141 D-G — no
 * `clin_allergy_reactions` child table). `clinical_status` is
 * ACTIVE | RESOLVED | INACTIVE and `verification_status` is
 * SUSPECTED | CONFIRMED | REFUTED, bound by the C.5 biconditional
 * `REFUTED <=> INACTIVE` (aggregate-enforced — ADR-141 D-D). `onset_date` is
 * nullable + mutable (no > 24 h backdate rule); `resolved_at` is set once by
 * `ResolveAllergy`.
 *
 * There is NO uniqueness constraint (a patient may legitimately hold the same
 * allergen from more than one facility / record — the ADR-140 D-P precedent),
 * NO CHECK constraint and NO self-FK (ADR-141 D-T). NO foreign key crosses a
 * table-prefix boundary (IC §10, ADR-093, ADR-135): `patient_id`,
 * `encounter_id`, `author_user_id` are plain indexed / stored uuids with NO
 * database FK.
 *
 * RLS is TENANT-SCOPED + FORCE with NO facility predicate — facility isolation
 * for the WRITE path is application-layer (`AllergyPolicy` + handler
 * refinement); the READ path is deliberately TENANT-WIDE (ADR-141 D-M — the
 * Q7 allergy-safety exception). The table is NOT append-only (the four C.5
 * transition commands are guarded versioned UPDATEs — `clin_allergy_history` is
 * the append-only sibling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_allergies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // ORIGINATING facility (ADR-141 D-B), from the encounter context
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // ORIGINATING encounter (ADR-141 D-B), plain uuid, NO FK
            $table->uuid('author_user_id');                // the recording clinician / nurse
            $table->string('authored_context')->nullable(); // CLINICIAN | NURSING (ADR-141 D-I)

            $table->string('category');                    // OPEN coded string, required non-blank, behaviour-neutral (ADR-141 D-F)
            $table->text('substance_name');                // REQUIRED non-blank clinical text (ADR-141 D-E)
            $table->string('substance_code')->nullable();  // OPEN string, optional, MUTABLE (ADR-141 D-E)
            $table->string('substance_code_system')->nullable(); // OPEN string, required iff code present, MUTABLE
            $table->text('reaction_description')->nullable(); // inline reaction (ADR-141 D-G); NEVER in events / audit
            $table->string('severity')->nullable();        // OPEN coded, behaviour-neutral (ADR-141 D-G)

            $table->string('clinical_status')->default('ACTIVE');       // ACTIVE | RESOLVED | INACTIVE (ADR-141 D-D)
            $table->string('verification_status')->default('SUSPECTED'); // SUSPECTED | CONFIRMED | REFUTED (ADR-141 D-D)

            $table->date('onset_date')->nullable();        // when the allergy / first reaction began — nullable + MUTABLE (ADR-141 D-H)
            $table->timestampTz('resolved_at')->nullable(); // resolution time — set once by ResolveAllergy (ADR-141 D-H)

            $table->timestampTz('occurred_at');            // CLINICAL time the allergy was entered on the record (ADR-132) — set once
            $table->timestampTz('recorded_at');            // server write time (ADR-132) — set once

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);                     // "a patient's allergies"
            $table->index(['tenant_id', 'patient_id', 'clinical_status']);  // "a patient's ACTIVE allergies" (the safety read)
            $table->index(['tenant_id', 'encounter_id']);                   // "allergies originated at an encounter"
        });

        Rls::enable('clin_allergies');
    }

    public function down(): void
    {
        Rls::disable('clin_allergies');
        Schema::dropIfExists('clin_allergies');
    }
};
