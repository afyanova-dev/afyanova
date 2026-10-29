<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 3 — per-facility capability enablement + services
 * (docs/00-foundation/06-facility-levels-and-capabilities.md §11–§16, §30–§31,
 * §60–§61, §105–§106; ADR-005/006/013).
 *
 * `org_capabilities` (the registry / definitions) is a global reference table
 * (2026_09_02_000010). These two are the tenant-and-facility-scoped enablement:
 * whether THIS facility operates the capability, and which services under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_facility_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('capability_code'); // → org_capabilities.code
            $table->string('state')->default('AVAILABLE'); // AVAILABLE → ENABLED → SUSPENDED → DISABLED (§11)
            $table->timestampTz('enabled_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['facility_id', 'capability_code']);
        });
        Rls::enable('org_facility_capabilities');

        Schema::create('org_facility_services', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('capability_code'); // the service sits under this capability (§12)
            $table->string('service_code');
            $table->string('name');
            $table->boolean('available')->default(true); // may be false while the capability stays ENABLED (§31)
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['facility_id', 'service_code']);
        });
        Rls::enable('org_facility_services');
    }

    public function down(): void
    {
        foreach (['org_facility_services', 'org_facility_capabilities'] as $t) {
            Rls::disable($t);
            Schema::dropIfExists($t);
        }
    }
};
