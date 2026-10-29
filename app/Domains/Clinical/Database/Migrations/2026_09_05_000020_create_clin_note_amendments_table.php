<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.1) — the append-only amendment log for a SIGNED clinical
 * note (12-clinical-domain.md §14, §15, Invariant 6; ADR-134). Corrections to a
 * signed note append a row here; `clin_notes.body` is NEVER rewritten.
 *
 * The ADR-132 context / attribution / clinical-time shape, plus the amendment's
 * own `amendment_type` / `body` / `reason`. `reason` is REQUIRED clinical data
 * (ADR-134) and lives on this row only — it is never copied to the audit entry.
 *
 * `clinical_note_id` carries a real foreign key to `clin_notes` — an
 * INTRA-domain reference (both `clin_`), permitted by IC §10 (only
 * cross-prefix FKs are forbidden). `patient_id` / `encounter_id` /
 * `author_user_id` are plain uuids, denormalised from the parent for query
 * convenience, with NO FK.
 *
 * Append-only at the database: `Rls::enableAppendOnly` adds RESTRICTIVE
 * no-UPDATE / no-DELETE policies (the `shared_dead_letters` precedent). The
 * `version` column exists only to satisfy the aggregate table shape
 * (IC §9.1 / §10) and stays 0 for the row's whole life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_note_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                   // copied from the parent note (ADR-132)
            $table->foreignUuid('clinical_note_id')->constrained('clin_notes'); // intra-domain FK (IC §10)
            $table->uuid('patient_id');                    // denormalised from the note, NO FK
            $table->uuid('encounter_id');                  // denormalised from the note, NO FK

            $table->string('amendment_type');             // ClinicalNoteAmendmentType: AMENDMENT | ADDENDUM | CORRECTION
            $table->text('body');                         // the correction content — Clinical-owned
            $table->string('reason');                     // REQUIRED (ADR-134) — clinical correction rationale
            $table->uuid('author_user_id');               // the amending clinician

            $table->timestampTz('occurred_at');           // CLINICAL time of the correction (ADR-132)
            $table->timestampTz('recorded_at');           // server write time (ADR-132)

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'clinical_note_id']); // "a note's amendments, in order"
        });

        Rls::enable('clin_note_amendments');
        Rls::enableAppendOnly('clin_note_amendments');
    }

    public function down(): void
    {
        Rls::disable('clin_note_amendments');
        Schema::dropIfExists('clin_note_amendments');
    }
};
