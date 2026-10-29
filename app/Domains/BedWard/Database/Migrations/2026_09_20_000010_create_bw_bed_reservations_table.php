<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bed reservations (24-bed-and-ward-management-domain.md §6, §8.3; ADR-149 D-A/D-D).
 *
 * `bed_id` is NOT NULL — the ADR-147 nullable "class-level hold" was dropped
 * (ADR-149 D4): every reservation names a specific bed. `encounter_id` /
 * `patient_id` stay nullable for a pre-encounter call-ahead. Concurrency is
 * guarded by a single partial unique index: at most one open (REQUESTED or
 * HELD) reservation per bed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bw_bed_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('bed_id');
            $table->uuid('encounter_id')->nullable();
            $table->uuid('patient_id')->nullable();
            $table->uuid('requested_by_user_id');

            $table->string('reason');
            $table->timestampTz('hold_until');
            $table->string('status')->default('REQUESTED');

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'bed_id', 'status']);
            $table->index(['tenant_id', 'encounter_id']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX bw_bed_reservations_one_open_per_bed
             ON bw_bed_reservations (bed_id)
             WHERE status IN ('REQUESTED', 'HELD')"
        );

        Rls::enable('bw_bed_reservations');
    }

    public function down(): void
    {
        Rls::disable('bw_bed_reservations');
        Schema::dropIfExists('bw_bed_reservations');
    }
};
