<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 3 — organisation / facility policy defaults + operating hours
 * (docs/00-foundation/02-organization.md §20–§22;
 * docs/00-foundation/06-facility-levels-and-capabilities.md §33).
 *
 * Policy values are opaque `jsonb` here — the OWNING domain (Billing, Inventory,
 * Notifications, …) interprets its own key. Organization only stores the
 * org-default and the permitted facility override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_organization_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('organization_id')->index();
            $table->string('key');
            $table->jsonb('value');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'key']);
        });
        Rls::enable('org_organization_policies');

        Schema::create('org_facility_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('key');
            $table->jsonb('value');
            $table->string('source')->default('FACILITY_OVERRIDE'); // ORG_DEFAULT | FACILITY_OVERRIDE
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['facility_id', 'key']);
        });
        Rls::enable('org_facility_policies');

        Schema::create('org_facility_operating_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday … 6 = Saturday
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('closed')->default(false);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['facility_id', 'day_of_week']);
        });
        Rls::enable('org_facility_operating_hours');
    }

    public function down(): void
    {
        foreach (['org_facility_operating_hours', 'org_facility_policies', 'org_organization_policies'] as $t) {
            Rls::disable($t);
            Schema::dropIfExists($t);
        }
    }
};
