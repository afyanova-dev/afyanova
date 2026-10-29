<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.2) — the vitals observation: one complete vitals set
 * recorded at ~one clinical time by one recorder (12-clinical-domain.md
 * §17–§22; ADR-131, ADR-132, ADR-138).
 *
 * The ADR-132 clinical record-context / attribution / clinical-time shape, in
 * IC §10 column order, then nine nullable typed measurement columns (WIDE
 * model — no child rows), then the ADR-138 D7 supersession columns, then
 * `occurred_at` / `recorded_at`, then `version`.
 *
 * FIXED CANONICAL STORAGE UNITS (ADR-138 D3) — no unit column: temperature °C,
 * heart rate bpm, respiratory rate breaths/min, systolic / diastolic BP mmHg,
 * SpO2 %, weight kg, height cm, pain score 0–10 NRS. BMI is DERIVED, never
 * stored. Structural safety bounds (ADR-138 D5) are enforced in the domain
 * (`VitalSigns` value object), not by DB check constraints.
 *
 * NO foreign key crosses a table-prefix boundary (IC §10, ADR-093, ADR-135):
 * `patient_id`, `encounter_id`, `author_user_id`, `superseded_by_observation_id`
 * are plain indexed uuids with NO database FK. `supersedes_observation_id`
 * carries an INTRA-domain self-referential FK (ADR-138 — permitted, IC §10).
 *
 * `facility_id` comes from the encounter context, never client input
 * (Invariant 12, ADR-135). RLS is tenant-scoped + FORCE (the `clin_notes`
 * precedent); the table is NOT append-only — the one-time supersession stamp
 * (ADR-138 D7) is a guarded versioned UPDATE, as `clin_notes` is not append-only
 * (only `clin_note_amendments` is).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_vital_sign_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();          // from the encounter context (ADR-135)
            $table->uuid('patient_id');                    // plain uuid, NO FK
            $table->uuid('encounter_id');                  // plain uuid, NO FK
            $table->uuid('author_user_id');                // the recorder
            $table->string('authored_context')->nullable(); // CLINICIAN | NURSING (ADR-138 D8)

            // Nine core measurements — fixed canonical units, all nullable (ADR-138 D2/D3/D4).
            $table->decimal('temperature', 4, 1)->nullable();       // °C
            $table->unsignedSmallInteger('heart_rate')->nullable(); // bpm
            $table->unsignedSmallInteger('respiratory_rate')->nullable(); // breaths/min
            $table->unsignedSmallInteger('systolic_bp')->nullable();  // mmHg
            $table->unsignedSmallInteger('diastolic_bp')->nullable(); // mmHg
            $table->unsignedSmallInteger('oxygen_saturation')->nullable(); // %
            $table->decimal('weight', 5, 1)->nullable();            // kg
            $table->decimal('height', 4, 1)->nullable();            // cm
            $table->unsignedSmallInteger('pain_score')->nullable(); // 0–10 NRS

            // Supersession / correction chain (ADR-138 D7). The self-referential
            // FK is added after CREATE TABLE (below) so the primary key exists.
            $table->uuid('supersedes_observation_id')->nullable();
            $table->timestampTz('superseded_at')->nullable();  // set once when a later correction supersedes this row
            $table->uuid('superseded_by_observation_id')->nullable(); // plain uuid, NO FK (forward reference)
            $table->text('reason')->nullable();                // correction rationale — clinical data (NULL on an original)

            $table->timestampTz('occurred_at');               // CLINICAL time of the vitals set (ADR-132)
            $table->timestampTz('recorded_at');               // server write time (ADR-132)

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);       // "a patient's vitals"
            $table->index(['tenant_id', 'encounter_id']);     // "an encounter's vitals"
            $table->index(['tenant_id', 'encounter_id', 'occurred_at']); // "an encounter's vitals, in order"

            $table->index('supersedes_observation_id');
        });

        // Intra-domain self-referential FK (ADR-138 — permitted, IC §10) — added
        // after the table (and its primary key) exists.
        Schema::table('clin_vital_sign_observations', function (Blueprint $table): void {
            $table->foreign('supersedes_observation_id')
                ->references('id')->on('clin_vital_sign_observations');
        });

        Rls::enable('clin_vital_sign_observations');
    }

    public function down(): void
    {
        Rls::disable('clin_vital_sign_observations');
        Schema::dropIfExists('clin_vital_sign_observations');
    }
};
