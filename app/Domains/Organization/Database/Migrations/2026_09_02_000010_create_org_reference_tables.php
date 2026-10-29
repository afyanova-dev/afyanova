<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 3 — Organization global reference data
 * (docs/00-foundation/06-facility-levels-and-capabilities.md §5–§10, §51, §105;
 * ADR-004/005/006).
 *
 * These are platform-wide catalogues — a code means the same thing in every
 * tenant. No `tenant_id`, no RLS (same pattern as `idn_permissions`). Populated
 * by `GlobalReferenceDataSeeder`, keyed on stable `code` (IC §23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_facility_types', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('code')->unique();      // DISPENSARY, HEALTH_CENTRE, HOSPITAL, …
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('org_care_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('code')->unique();      // PRIMARY, HEALTH_CENTRE, DISTRICT, REGIONAL, ZONAL, NATIONAL
            $table->string('name');
            $table->unsignedSmallInteger('rank');  // ordering only — never a workflow rule (ADR-004)
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('org_ownership_types', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('code')->unique();      // PUBLIC, PRIVATE, FAITH_BASED, NGO, COMMUNITY, OTHER
            $table->string('name');
            $table->timestampsTz();
        });

        Schema::create('org_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('code')->unique();      // OUTPATIENT_CARE, LABORATORY, THEATRE, ICU, …
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('category');            // CARE, DIAGNOSTICS, MEDICATION, SURGICAL, CRITICAL_CARE, SUPPORT (§70)
            $table->string('feature_key')->nullable();
            $table->string('required_domain')->nullable();
            $table->string('status')->default('ACTIVE'); // registry entry lifecycle (ACTIVE | RETIRED)
            $table->timestampsTz();
        });

        Schema::create('org_capability_dependencies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('capability_code');
            $table->string('depends_on_code');
            $table->timestampsTz();

            $table->unique(['capability_code', 'depends_on_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_capability_dependencies');
        Schema::dropIfExists('org_capabilities');
        Schema::dropIfExists('org_ownership_types');
        Schema::dropIfExists('org_care_levels');
        Schema::dropIfExists('org_facility_types');
    }
};
