<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 (increment A.4b) — SECONDARY / additional patient contact points
 * (10-patient-domain.md §31-§32, §152; ADR-126).
 *
 * Tenant-scoped, NOT facility-scoped — identity-level data (ADR-114). Its OWN
 * aggregate (ADR-126 D-1). `patient_id` is a plain indexed uuid, NO database
 * foreign key (shipped precedent).
 *
 * This table is NEVER a second source of truth for pat_patients.primary_phone
 * / email (ADR-126 D-2). No uniqueness / cardinality constraint — a patient may
 * hold several contacts of the same type, and `is_preferred` is a hint, not a
 * "exactly one" rule (D-8). No verification columns in A.4b (D-5 — deferred,
 * no consumer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pat_patient_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('patient_id')->index();

            $table->string('type');                       // ContactType enum (coded channel)
            $table->string('value');                      // raw, as entered (PHI)
            $table->string('normalized_value');           // folded — email lower-cased, phone digits
            $table->string('label')->nullable();          // free text: "work", "spouse's phone"
            $table->boolean('is_preferred')->nullable();  // §31 "Preferred Contact Method" — a hint, not a constraint

            $table->string('status')->default('ACTIVE');  // ContactStatus enum (ADR-126 D-8)
            $table->uuid('recorded_by_user_id')->nullable();
            $table->uuid('removed_by_user_id')->nullable();
            $table->timestampTz('removed_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id', 'type']); // "list a patient's contact points"
        });

        Rls::enable('pat_patient_contacts');
    }

    public function down(): void
    {
        Rls::disable('pat_patient_contacts');
        Schema::dropIfExists('pat_patient_contacts');
    }
};
