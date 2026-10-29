<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 (increment E.1) — the encounter: one patient attendance at one
 * facility (11-encounter-domain.md; ADR-101, ADR-104, ADR-108, ADR-115,
 * ADR-120, ADR-121, ADR-130).
 *
 * Facility-scoped shape (IC §10) — `tenant_id` + `facility_id` both NOT NULL:
 * an encounter happens at exactly one facility (Invariant 3). Both come from
 * TenantContext, never client input (Invariant 12, ADR-037).
 *
 * `patient_id` references `pat_patients`, `department_id` / `unit_id` reference
 * `org_departments` / `org_units` — all plain indexed uuids with NO database
 * foreign key. No shipped migration crosses a table-prefix boundary with a FK
 * (IC §10, ADR-093); the tenant-correct guard is `CreateEncounter` validating
 * the patient through `PatientApi` first. `room_id` / `bed_id` are NOT here:
 * operational location + movement history are Queueing & Patient Flow's, bed
 * occupancy is Bed & Ward Management's (ADR-107, ADR-130 B).
 *
 * ADR-121 ACTIVE-ATTENDANCE UNIQUENESS: a PARTIAL unique index over
 * (tenant_id, patient_id, facility_id) restricted to status IN
 * ('ARRIVED','ACTIVE') is the final concurrency guard (Invariant 12b). Laravel's
 * schema builder cannot express a partial index, so it is raw SQL (the same
 * approach `pat_patient_identifiers` and `Rls` use).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enc_encounters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('patient_id')->index();

            $table->string('encounter_number');                  // ADR-115: ENC-00000001, tenant-wide unique
            $table->string('encounter_type');                    // EncounterType enum (ADR-120)
            $table->string('status')->default('ARRIVED');        // EncounterStatus enum (ADR-121)
            $table->string('disposition')->nullable();           // EncounterDisposition enum — set by later commands (ADR-130 A)

            // Coarse care-context location only (ADR-130 B) — plain uuids, no FK.
            $table->uuid('department_id')->nullable();
            $table->uuid('unit_id')->nullable();

            // Distinct business timestamps (§31, §32) — each set once by its transition command.
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('admitted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            // Provenance (§93–§94; ADR-130 E). `created_source` is a free string in PHASE 5,
            // mirroring pat_patients.registration_source; no `channel` column.
            $table->uuid('created_by_user_id');
            $table->string('created_source')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'encounter_number']); // ADR-115 — tenant-wide, not facility-scoped
            $table->index(['tenant_id', 'patient_id']);        // "a patient's encounters"
            $table->index(['tenant_id', 'facility_id', 'status']); // "active encounters at a facility"
        });

        // ADR-121 — at most one ARRIVED/ACTIVE encounter per (tenant, patient, facility).
        DB::statement(
            "CREATE UNIQUE INDEX enc_one_active_per_patient_facility
             ON enc_encounters (tenant_id, patient_id, facility_id)
             WHERE status IN ('ARRIVED', 'ACTIVE')"
        );

        Rls::enable('enc_encounters');
    }

    public function down(): void
    {
        Rls::disable('enc_encounters');
        Schema::dropIfExists('enc_encounters');
    }
};
