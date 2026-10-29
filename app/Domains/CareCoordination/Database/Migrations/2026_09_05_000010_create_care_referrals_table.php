<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals (26-care-coordination-domain.md §6; ADR-151 D-D). Reuses the
 * ADR-132 context+time shape.
 *
 * `patient_id` NOT NULL (a genuine gap in the domain doc's own §6
 * illustrative shape — closed by ADR-151 D-D). `target_type`/`target_ref`
 * implement the doc's own "target (INTERNAL facility_id | EXTERNAL provider
 * ref)" as one open-coded field + one string, not two nullable columns.
 * A single row is visible tenant-wide (RLS is tenant-scoped, not
 * facility-scoped — the Patient MPI precedent); the receiving facility acts
 * on the SAME row via ReceiveReferral/AcceptReferral (§14: "Internal
 * referrals link two facilities within the tenant").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id'); // = source_facility_id (ADR-132 originating-context convention)

            $table->uuid('patient_id');
            $table->string('direction')->default('OUTBOUND');
            $table->boolean('implies_handover')->default(false);
            $table->uuid('source_encounter_id')->nullable();
            $table->string('target_type');
            $table->string('target_ref');
            $table->string('specialty_requested')->nullable();
            $table->string('priority')->default('ROUTINE');
            $table->uuid('clinical_reason_ref')->nullable();
            $table->uuid('consent_basis_ref')->nullable();
            $table->string('status')->default('DRAFT');
            $table->uuid('target_encounter_id')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'target_type', 'target_ref', 'status']);
        });

        Rls::enable('care_referrals');
    }

    public function down(): void
    {
        Rls::disable('care_referrals');
        Schema::dropIfExists('care_referrals');
    }
};
