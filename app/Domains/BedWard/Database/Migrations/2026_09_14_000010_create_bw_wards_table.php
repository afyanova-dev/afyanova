<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structural master for inpatient wards (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-B, ADR-148 D-B).
 *
 * Tier 1 structural reference data: outlives any patient stay, no patient_id or
 * encounter_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bw_wards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->string('code');
            $table->string('name');
            $table->string('ward_type');
            $table->string('capability_link')->nullable();
            $table->unsignedInteger('capacity');
            $table->string('status')->default('ACTIVE');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'code']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('bw_wards');
    }

    public function down(): void
    {
        Rls::disable('bw_wards');
        Schema::dropIfExists('bw_wards');
    }
};
