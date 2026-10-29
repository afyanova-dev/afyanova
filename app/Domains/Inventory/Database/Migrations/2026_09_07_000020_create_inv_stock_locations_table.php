<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Inv.1) — the Stock Location catalog
 * (18-inventory-and-warehousing-domain.md §15–§23, §251, §358; ADR-170 D-D).
 *
 * A SINGLE flat table with an open-coded `location_type` discriminator
 * (WAREHOUSE | STORE | QUARANTINE | LOCATION | BIN — §15–§19) and an optional
 * `parent_location_id` self-reference (the §17 "Warehouse ↓ Store ↓ Location ↓
 * Bin" tree), NOT four tables: §251 nests warehouse / store / bin under one
 * "Location" node, §410 says these concepts "may be enabled according to
 * facility complexity", and the "small facilities must operate inventory without
 * enterprise warehouse complexity" invariant forbids forcing a 4-level
 * hierarchy. A dispensary registers ONE STORE with a null parent (§20 / §359).
 *
 * Facility-scoped (§23 / §25 — every stock location belongs to a facility);
 * organization-wide / central-warehouse locations (§26 / §27, null facility)
 * are deferred to Inv.4. Console-authored (`inventory:define-location` —
 * ADR-170 D-D): no HTTP command, no permission key ("Inv.0").
 *
 * `code` is unique among ACTIVE rows per (tenant, facility) — locations are
 * facility-scoped, unlike the tenant-wide item catalog. Hierarchy TYPE-ordering
 * validation is deferred; Inv.1 enforces only `parent.facility_id ==
 * facility_id` (in the console command).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('code');           // unique per (tenant, facility) WHERE active
            $table->string('name');
            $table->string('location_type');  // §15–§19 — open-coded normalised uppercase
            $table->uuid('parent_location_id')->nullable(); // self-ref (§17); FK added below

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'active']);
            $table->index('parent_location_id');
        });

        // The self-referential parent FK — added after the table (and its
        // primary key) exists (§17; the intra-`inv_` prefix keeps it
        // boundary-clean — arch: FK target named).
        Schema::table('inv_stock_locations', function (Blueprint $table): void {
            $table->foreign('parent_location_id')->references('id')->on('inv_stock_locations');
        });

        DB::statement(
            'create unique index inv_stock_locations_facility_code_active_unique '.
            'on inv_stock_locations (tenant_id, facility_id, code) where active'
        );

        Rls::enable('inv_stock_locations');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_locations');
        Schema::dropIfExists('inv_stock_locations');
    }
};
