<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 (scope A) — patient external / official identifiers
 * (10-patient-domain.md §39-43, §114; ADR-117, ADR-122).
 *
 * A SEPARATE aggregate from pat_patients (own lifecycle: added / verified long
 * after registration — IC §4.1). Tenant-scoped, NOT facility-scoped:
 * identifiers are identity-level and identity spans the tenant (ADR-114).
 *
 * `patient_id` references pat_patients (same `pat_` prefix) as a plain indexed
 * uuid with NO database foreign key — the established precedent (no shipped
 * migration declares one, even intra-domain; see the pat_patient_registrations
 * migration for the full rationale). The tenant-correct guard is
 * AddPatientIdentifierHandler loading the patient through the RLS-scoped
 * PatientRepository first.
 *
 * ADR-117 VERIFIED-IDENTIFIER UNIQUENESS: a PARTIAL unique index over
 * (tenant_id, identifier_type, normalized_value) restricted to
 * verification_status = 'VERIFIED'. Unverified values are deliberately NOT
 * constrained — they are frequently mistyped (§43) and a hard constraint
 * would block legitimate registrations. Laravel's schema builder cannot
 * express a partial index, so it is raw SQL (the same approach Rls uses for
 * policies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pat_patient_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('patient_id')->index();

            $table->string('identifier_type');                       // IdentifierType enum
            $table->string('value');                                 // raw, as entered (PHI)
            $table->string('normalized_value');                      // case-folded, alphanumerics only — matching key
            $table->string('issuing_authority')->nullable();         // §39

            $table->string('verification_status')->default('UNVERIFIED'); // IdentifierVerificationStatus enum (§42)
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('verified_by_user_id')->nullable();
            $table->string('verification_source')->nullable();       // §167-168

            $table->date('effective_from')->nullable();              // §39
            $table->date('effective_to')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id', 'identifier_type']); // "list a patient's identifiers of a type"
        });

        // ADR-117 — partial unique: at most one VERIFIED row per (tenant, type, value).
        DB::statement(
            "CREATE UNIQUE INDEX pat_patient_identifiers_verified_unique
             ON pat_patient_identifiers (tenant_id, identifier_type, normalized_value)
             WHERE verification_status = 'VERIFIED'"
        );

        Rls::enable('pat_patient_identifiers');
    }

    public function down(): void
    {
        Rls::disable('pat_patient_identifiers');
        Schema::dropIfExists('pat_patient_identifiers');
    }
};
