<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 3 — the organisational hierarchy
 * (docs/00-foundation/02-organization.md §33; docs/05 §101; ADR-002/003/007/111).
 *
 *   Tenant → Organization → Facility → Department → Unit → Room
 *
 * IC §10 column shape on every tenant-scoped table
 * (id/tenant_id/facility_id?/…/version/timestampsTz). Cross-domain refs are bare
 * uuids with NO FK (ADR-093); within-domain FKs are allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // tenant-only
        Schema::create('org_organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('ACTIVE'); // ACTIVE | SUSPENDED
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        Rls::enable('org_organizations');

        // tenant-only (a facility is org-scoped, not facility-scoped by itself)
        Schema::create('org_facilities', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('code');
            $table->string('facility_type');   // → org_facility_types.code
            $table->string('care_level');      // → org_care_levels.code
            $table->string('ownership_type');  // → org_ownership_types.code
            $table->string('status')->default('ACTIVE'); // ACTIVE | SUSPENDED | CLOSED
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
            $table->index('organization_id');
        });
        Rls::enable('org_facilities');

        // facility-scoped
        Schema::create('org_departments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('name');
            $table->string('code');
            $table->string('kind')->nullable(); // free label (CLINICAL / SUPPORT / …) — not a rule
            $table->string('status')->default('ACTIVE');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['facility_id', 'code']);
        });
        Rls::enable('org_departments');

        Schema::create('org_units', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('department_id')->index();
            $table->string('name');
            $table->string('code');
            $table->string('unit_type')->default('GENERIC'); // generic by design (ADR-007)
            $table->string('status')->default('ACTIVE');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['facility_id', 'code']);
        });
        Rls::enable('org_units');

        // facility-scoped — the physical-space master (ADR-111). Bed occupancy,
        // OR state, schedulable availability, equipment assets are OTHER domains'.
        Schema::create('org_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('unit_id')->nullable()->index();
            $table->string('name');
            $table->string('code');
            $table->string('room_type')->default('GENERIC'); // CONSULTATION / INJECTION / DRESSING / THEATRE / STORE / WARD_ROOM / …
            $table->string('status')->default('ACTIVE');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['facility_id', 'code']);
        });
        Rls::enable('org_rooms');
    }

    public function down(): void
    {
        foreach (['org_rooms', 'org_units', 'org_departments', 'org_facilities', 'org_organizations'] as $t) {
            Rls::disable($t);
            Schema::dropIfExists($t);
        }
    }
};
