<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative bed occupancies (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-B/D-D, ADR-148 D-B/D-C).
 *
 * Tier 2 per-patient per-encounter occupancy record.
 * Concurrency is guarded both by optimistic versioning and by two hard partial
 * unique indexes:
 *   - bw_bed_occupancies_one_active_per_bed: at most one ACTIVE occupancy per bed (Invariant 2)
 *   - bw_bed_occupancies_one_active_per_encounter: at most one ACTIVE bed occupancy per encounter (Invariant 4)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bw_bed_occupancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('bed_id');
            $table->uuid('encounter_id');
            $table->uuid('patient_id');
            $table->uuid('assigned_by_user_id');

            $table->string('status')->default('ACTIVE');
            $table->string('end_reason')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'bed_id', 'status']);
            $table->index(['tenant_id', 'encounter_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX bw_bed_occupancies_one_active_per_bed
             ON bw_bed_occupancies (bed_id)
             WHERE status = 'ACTIVE'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX bw_bed_occupancies_one_active_per_encounter
             ON bw_bed_occupancies (encounter_id)
             WHERE status = 'ACTIVE'"
        );

        Rls::enable('bw_bed_occupancies');
    }

    public function down(): void
    {
        Rls::disable('bw_bed_occupancies');
        Schema::dropIfExists('bw_bed_occupancies');
    }
};
