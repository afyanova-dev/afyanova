<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 (scope A / increment A.4) — patient relationships: emergency contact /
 * next of kin / guardian / parent / … (10-patient-domain.md §35-§38, §152;
 * ADR-126).
 *
 * ONE role-typed table (ADR-126 D-6), NOT separate emergency-contact and
 * next-of-kin tables. Tenant-scoped, NOT facility-scoped — identity-level data,
 * identity spans the tenant (ADR-114).
 *
 * Its OWN aggregate (ADR-126 D-1), not a child of pat_patients. `patient_id` is
 * a plain indexed uuid with NO database foreign key — the established precedent
 * (see the pat_patient_registrations migration for the full rationale); the
 * tenant-correct guard is RecordPatientRelationshipHandler loading the patient
 * through the RLS-scoped PatientRepository first.
 *
 * The related party is FREE-FORM (ADR-126 D-7): related_name / relationship_label
 * / related_phone / note — no related_patient_id in PHASE 5. No uniqueness /
 * cardinality constraint: a patient may have several relationships of the same
 * role (ADR-126 D-8 — no invented "exactly one next of kin").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pat_patient_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('patient_id')->index();

            $table->string('role');                          // RelationshipRole enum (coded)
            $table->string('related_name');                  // PHI
            $table->string('relationship_label')->nullable(); // free text: "aunt", "family friend" (§35/§36 "Relationship")
            $table->string('related_phone')->nullable();      // PHI
            $table->text('note')->nullable();

            $table->string('status')->default('ACTIVE');      // RelationshipStatus enum (ADR-126 D-8)
            $table->uuid('recorded_by_user_id')->nullable();
            $table->uuid('removed_by_user_id')->nullable();
            $table->timestampTz('removed_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id', 'role']); // "list a patient's emergency contacts"
        });

        Rls::enable('pat_patient_relationships');
    }

    public function down(): void
    {
        Rls::disable('pat_patient_relationships');
        Schema::dropIfExists('pat_patient_relationships');
    }
};
