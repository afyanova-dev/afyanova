<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 (increment A.4c) — patient addresses (10-patient-domain.md §33-§34,
 * §152; ADR-126).
 *
 * Tenant-scoped, NOT facility-scoped — identity-level data (ADR-114). Its OWN
 * aggregate (ADR-126 D-1). `patient_id` is a plain indexed uuid, NO database
 * foreign key (shipped precedent).
 *
 * Fixed §33 columns (region / district / ward / street_or_village / postal_code
 * / notes) — "extensible" (§33) means additive migrations, not a JSON blob. No
 * uniqueness / cardinality constraint and no primary/default flag (§33 has no
 * "preferred address" concept; the "current address" is the ACTIVE row of a
 * type — ADR-126 D-8). §34 ("must not erase historical records"): a MOVE is a
 * new row with the prior one deactivated; rows are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pat_patient_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('patient_id')->index();

            $table->string('type');                       // AddressType enum (coded)
            $table->string('region')->nullable();         // §33
            $table->string('district')->nullable();
            $table->string('ward')->nullable();
            $table->string('street_or_village')->nullable();
            $table->string('postal_code')->nullable();    // "Postal Information where applicable"
            $table->text('notes')->nullable();            // "Other Address Details"

            $table->string('status')->default('ACTIVE');  // AddressStatus enum (ADR-126 D-8)
            $table->uuid('recorded_by_user_id')->nullable();
            $table->uuid('removed_by_user_id')->nullable();
            $table->timestampTz('removed_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id', 'type']); // "list a patient's addresses"
        });

        Rls::enable('pat_patient_addresses');
    }

    public function down(): void
    {
        Rls::disable('pat_patient_addresses');
        Schema::dropIfExists('pat_patient_addresses');
    }
};
