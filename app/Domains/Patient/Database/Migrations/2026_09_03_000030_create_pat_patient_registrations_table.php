<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 Slice 2 — patient facility participation (10-patient-domain.md
 * §109-111; ADR-114). A SEPARATE aggregate from pat_patients (own lifecycle,
 * own transaction boundary — IC §4.1).
 *
 * Facility-scoped shape (IC §10) — the one pat_ table where facility_id IS
 * part of the identity: this table records the relationship itself, unlike
 * pat_patients which ADR-114 forbids from carrying facility_id.
 *
 * `patient_id` references pat_patients (same `pat_` prefix — an intra-domain
 * foreign key would be allowed here, IC §10) but is a plain indexed uuid
 * column with NO database-level foreign-key constraint, matching the
 * established precedent: no shipped migration declares a foreign key, even
 * for an intra-domain child (e.g. org_departments.facility_id references
 * org_facilities the same way). A bare foreign key would only guarantee the
 * referenced row exists SOMEWHERE, not that it is in the same tenant, so it
 * adds no correctness a plain column lacks — the real guard is
 * RegisterPatientAtFacilityHandler loading the patient through the
 * tenant-scoped PatientRepository (RLS-correct) before creating a
 * registration. A database-level foreign key also creates cross-connection
 * MVCC visibility failures against the raw pgsql_raw RLS-test connection and
 * blocks truncating pat_patients from the Slice 1 RLS test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pat_patient_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('patient_id')->index();

            $table->string('status')->default('ACTIVE'); // ADR-114 mandates the column; PHASE 5 uses a single value
            $table->timestampTz('first_registered_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'patient_id']); // ADR-114 — IC §9.1 "at most one X"
        });

        Rls::enable('pat_patient_registrations');
    }

    public function down(): void
    {
        Rls::disable('pat_patient_registrations');
        Schema::dropIfExists('pat_patient_registrations');
    }
};
