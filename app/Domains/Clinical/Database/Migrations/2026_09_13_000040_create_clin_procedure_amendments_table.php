<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment CP.1) — the append-only correction log for a completed /
 * aborted procedure (13-clinical-procedures-domain.md §81, §139, Inv 6; ADR-137
 * — "completed-procedure corrections use the ADR-134 append-only model";
 * ADR-146 D-M).
 *
 * The ADR-132 context / attribution / clinical-time shape, plus the
 * amendment's own `amendment_type` / `body` / `reason` / `changed_fields` — the
 * `clin_note_amendments` shape, extended with `changed_fields` (which
 * `clin_procedures` columns this amendment touched — a procedure amendment MAY
 * apply the corrected values onto the row itself, unlike a `ClinicalNote`'s
 * immutable signed body). `reason` is REQUIRED clinical data (ADR-134) and
 * lives on this row only — it is never copied to the audit entry.
 *
 * `procedure_id` carries a real foreign key to `clin_procedures` — an
 * INTRA-domain reference (both `clin_`), permitted by IC §10. `patient_id` /
 * `encounter_id` / `author_user_id` are plain uuids, denormalised from the
 * parent for query convenience, with NO FK.
 *
 * Append-only at the database: `Rls::enableAppendOnly` adds RESTRICTIVE
 * no-UPDATE / no-DELETE policies (the `clin_note_amendments` precedent). The
 * `version` column exists only to satisfy the aggregate table shape (IC §9.1 /
 * §10) and stays 0 for the row's whole life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_procedure_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                    // copied from the parent procedure
            $table->foreignUuid('procedure_id')->constrained('clin_procedures'); // intra-domain FK (IC §10)
            $table->uuid('patient_id');                      // denormalised, NO FK
            $table->uuid('encounter_id');                    // denormalised, NO FK

            $table->string('amendment_type');                // ProcedureAmendmentType: AMENDMENT | ADDENDUM | CORRECTION
            $table->text('body');                             // the correction narrative
            $table->string('reason');                         // REQUIRED (ADR-134 pattern)
            $table->jsonb('changed_fields')->nullable();      // which clin_procedures fields this amendment touched
            $table->uuid('author_user_id');                   // the amending clinician

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0); // stays 0 — append-only
            $table->timestampsTz();

            $table->index(['tenant_id', 'procedure_id']);      // "a procedure's amendments, in order"
        });

        Rls::enable('clin_procedure_amendments');
        Rls::enableAppendOnly('clin_procedure_amendments');
    }

    public function down(): void
    {
        Rls::disable('clin_procedure_amendments');
        Schema::dropIfExists('clin_procedure_amendments');
    }
};
