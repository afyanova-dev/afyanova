<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 — the tenant-wide Master Patient Index (10-patient-domain.md;
 * ADR-114, ADR-115, ADR-116, ADR-119, ADR-125).
 *
 * Tenant-only scope shape (IC §10): no `facility_id` — ADR-114 forbids an
 * authoritative facility column on patient identity. Facility participation
 * is the separate `pat_patient_registrations` aggregate (a later slice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pat_patients', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('patient_number');   // ADR-115: PAT-00000001, tenant-wide unique

            $table->string('given_name');
            $table->string('family_name');
            $table->string('middle_name')->nullable();
            $table->string('display_name');
            $table->string('sex');                                // Sex enum
            $table->date('date_of_birth')->nullable();
            $table->string('dob_precision')->default('UNKNOWN');  // DobPrecision enum
            $table->string('primary_phone')->nullable();
            $table->string('email')->nullable();

            $table->string('status')->default('ACTIVE');          // PatientStatus enum (ADR-116)
            $table->boolean('is_provisional')->default(false);    // ADR-119

            // ADR-125 — reference field only; no Documents integration in PHASE 5.
            $table->uuid('photo_document_id')->nullable();
            $table->timestampTz('photo_captured_at')->nullable();
            $table->uuid('photo_captured_by')->nullable();

            // Provenance (10-patient-domain.md §53-54) — not an isolation axis.
            $table->string('registration_source')->nullable();
            $table->uuid('registered_by_user_id')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'patient_number']); // ADR-114/115 — tenant-wide, not facility-scoped
        });

        Rls::enable('pat_patients');
    }

    public function down(): void
    {
        Rls::disable('pat_patients');
        Schema::dropIfExists('pat_patients');
    }
};
